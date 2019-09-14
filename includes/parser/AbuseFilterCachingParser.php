<?php
/**
 * AbuseFilterCachingParser is the version of AbuseFilterParser which parses
 * the code into an abstract syntax tree before evaluating it, and caches that
 * tree.
 *
 * It currently inherits AbuseFilterParser in order to avoid code duplication.
 * In future, this code will replace current AbuseFilterParser entirely.
 *
 * @todo Override checkSyntax and make it only try to build the AST. That would mean faster results,
 *   and no need to mess with DUNDEFINED and the like. However, we must first try to reduce the
 *   amount of runtime-only exceptions, and try to detect them in the AFPTreeParser instead.
 *   Otherwise, people may be able to save a broken filter without the syntax check reporting that.
 */
class AbuseFilterCachingParser extends AbuseFilterParser {
	const CACHE_VERSION = 1;

	/**
	 * Return the generated version of the parser for cache invalidation
	 * purposes.  Automatically tracks list of all functions and invalidates the
	 * cache if it is changed.
	 * @return string
	 */
	public static function getCacheVersion() {
		static $version = null;
		if ( $version !== null ) {
			return $version;
		}

		$versionKey = [
			self::CACHE_VERSION,
			AFPTreeParser::CACHE_VERSION,
			AbuseFilterTokenizer::CACHE_VERSION,
			array_keys( AbuseFilterParser::$mFunctions ),
			array_keys( AbuseFilterParser::$mKeywords ),
		];
		$version = hash( 'sha256', serialize( $versionKey ) );

		return $version;
	}

	/**
	 * Resets the state of the parser
	 */
	public function resetState() {
		$this->mVariables = new AbuseFilterVariableHolder;
		$this->mCur = new AFPToken();
		$this->mCondCount = 0;
		$this->mAllowShort = true;
	}

	/**
	 * @param string $code
	 * @return AFPData
	 */
	public function intEval( $code ) : AFPData {
		$tree = $this->getTree( $code );

		$res = $this->evalTree( $tree );

		if ( $res->getType() === AFPData::DUNDEFINED ) {
			$res = new AFPData( AFPData::DBOOL, false );
		}
		return $res;
	}

	/**
	 * @param string $code
	 * @return AFPSyntaxTree
	 */
	private function getTree( $code ) : AFPSyntaxTree {
		return $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey(
				__CLASS__,
				self::getCacheVersion(),
				hash( 'sha256', $code )
			),
			BagOStuff::TTL_DAY,
			function () use ( $code ) {
				$parser = new AFPTreeParser( $this->cache, $this->logger );
				$parser->setFilter( $this->mFilter );
				return $parser->parse( $code );
			}
		);
	}

	/**
	 * @param AFPSyntaxTree $tree
	 * @return AFPData
	 */
	private function evalTree( AFPSyntaxTree $tree ) : AFPData {
		$root = $tree->getRoot();

		if ( !$root ) {
			return new AFPData( AFPData::DNULL );
		}

		return $this->evalNode( $root );
	}

	/**
	 * Evaluate the value of the specified AST node.
	 *
	 * @param AFPTreeNode $node The node to evaluate.
	 * @return AFPData|AFPTreeNode|string
	 * @throws AFPException
	 * @throws AFPUserVisibleException
	 * @throws MWException
	 */
	private function evalNode( AFPTreeNode $node ) {
		// A lot of AbuseFilterParser features rely on $this->mCur->pos or
		// $this->mPos for error reporting.
		// FIXME: this is a hack which needs to be removed when the parsers are merged.
		$this->mPos = $node->position;
		$this->mCur->pos = $node->position;

		switch ( $node->type ) {
			case AFPTreeNode::ATOM:
				$tok = $node->children;
				switch ( $tok->type ) {
					case AFPToken::TID:
						return $this->getVarValue( strtolower( $tok->value ) );
					case AFPToken::TSTRING:
						return new AFPData( AFPData::DSTRING, $tok->value );
					case AFPToken::TFLOAT:
						return new AFPData( AFPData::DFLOAT, $tok->value );
					case AFPToken::TINT:
						return new AFPData( AFPData::DINT, $tok->value );
					/** @noinspection PhpMissingBreakStatementInspection */
					case AFPToken::TKEYWORD:
						switch ( $tok->value ) {
							case "true":
								return new AFPData( AFPData::DBOOL, true );
							case "false":
								return new AFPData( AFPData::DBOOL, false );
							case "null":
								return new AFPData( AFPData::DNULL );
						}
					// Fallthrough intended
					default:
						// @codeCoverageIgnoreStart
						throw new AFPException( "Unknown token provided in the ATOM node" );
						// @codeCoverageIgnoreEnd
				}
			case AFPTreeNode::ARRAY_DEFINITION:
				$items = array_map( [ $this, 'evalNode' ], $node->children );
				return new AFPData( AFPData::DARRAY, $items );

			case AFPTreeNode::FUNCTION_CALL:
				$functionName = $node->children[0];
				$args = array_slice( $node->children, 1 );

				$dataArgs = array_map( [ $this, 'evalNode' ], $args );

				return $this->callFunc( $functionName, $dataArgs );
			case AFPTreeNode::ARRAY_INDEX:
				list( $array, $offset ) = $node->children;

				$array = $this->evalNode( $array );

				if ( $array->getType() === AFPData::DUNDEFINED ) {
					return new AFPData( AFPData::DUNDEFINED );
				}

				if ( $array->getType() !== AFPData::DARRAY ) {
					throw new AFPUserVisibleException( 'notarray', $node->position, [] );
				}

				$offset = $this->evalNode( $offset )->toInt();

				$array = $array->toArray();
				if ( count( $array ) <= $offset ) {
					throw new AFPUserVisibleException( 'outofbounds', $node->position,
						[ $offset, count( $array ) ] );
				}

				return $array[$offset];

			case AFPTreeNode::UNARY:
				list( $operation, $argument ) = $node->children;
				$argument = $this->evalNode( $argument );
				if ( $operation === '-' ) {
					return $argument->unaryMinus();
				}
				return $argument;

			case AFPTreeNode::KEYWORD_OPERATOR:
				list( $keyword, $leftOperand, $rightOperand ) = $node->children;
				$func = self::$mKeywords[$keyword];
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );

				if (
					$leftOperand->getType() === AFPData::DUNDEFINED ||
					$rightOperand->getType() === AFPData::DUNDEFINED
				) {
					$result = new AFPData( AFPData::DUNDEFINED );
				} else {
					$this->raiseCondCount();

					// @phan-suppress-next-line PhanParamTooMany Not every function needs the position
					$result = $this->$func( $leftOperand, $rightOperand, $node->position );
				}

				return $result;
			case AFPTreeNode::BOOL_INVERT:
				list( $argument ) = $node->children;
				$argument = $this->evalNode( $argument );
				return $argument->boolInvert();

			case AFPTreeNode::POW:
				list( $base, $exponent ) = $node->children;
				$base = $this->evalNode( $base );
				$exponent = $this->evalNode( $exponent );
				return $base->pow( $exponent );

			case AFPTreeNode::MUL_REL:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				return $leftOperand->mulRel( $rightOperand, $op, $node->position );

			case AFPTreeNode::SUM_REL:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				switch ( $op ) {
					case '+':
						return $leftOperand->sum( $rightOperand );
					case '-':
						return $leftOperand->sub( $rightOperand );
					default:
						// @codeCoverageIgnoreStart
						throw new AFPException( "Unknown sum-related operator: {$op}" );
						// @codeCoverageIgnoreEnd
				}

			case AFPTreeNode::COMPARE:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				$this->raiseCondCount();
				return $leftOperand->compareOp( $rightOperand, $op );

			case AFPTreeNode::LOGIC:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$value = $leftOperand->getType() === AFPData::DUNDEFINED ? false : $leftOperand->toBool();
				// Short-circuit.
				if ( ( !$value && $op === '&' ) || ( $value && $op === '|' ) ) {
					if ( $rightOperand instanceof AFPTreeNode ) {
						$this->maybeDiscardNode( $rightOperand );
					}
					return $leftOperand;
				}
				$rightOperand = $this->evalNode( $rightOperand );
				return $leftOperand->boolOp( $rightOperand, $op );

			case AFPTreeNode::CONDITIONAL:
				list( $condition, $valueIfTrue, $valueIfFalse ) = $node->children;
				$condition = $this->evalNode( $condition );
				$isTrue = $condition->getType() === AFPData::DUNDEFINED ? false : $condition->toBool();
				if ( $isTrue ) {
					if ( $valueIfFalse !== null ) {
						$this->maybeDiscardNode( $valueIfFalse );
					}
					return $this->evalNode( $valueIfTrue );
				} else {
					$this->maybeDiscardNode( $valueIfTrue );
					return $valueIfFalse !== null
						? $this->evalNode( $valueIfFalse )
						// We assume null as default if the else is missing
						: new AFPData( AFPData::DNULL );
				}

			case AFPTreeNode::ASSIGNMENT:
				list( $varName, $value ) = $node->children;
				$value = $this->evalNode( $value );
				$this->setUserVariable( $varName, $value );
				return $value;

			case AFPTreeNode::INDEX_ASSIGNMENT:
				list( $varName, $offset, $value ) = $node->children;

				if ( $this->isBuiltinVar( $varName ) ) {
					throw new AFPUserVisibleException( 'overridebuiltin', $node->position, [ $varName ] );
				}
				$array = $this->getVarValue( $varName );

				$value = $this->evalNode( $value );
				if ( $array->getType() !== AFPData::DUNDEFINED ) {
					// If it's a DUNDEFINED, leave it as is
					if ( $array->getType() !== AFPData::DARRAY ) {
						throw new AFPUserVisibleException( 'notarray', $node->position, [] );
					}

					$offset = $this->evalNode( $offset )->toInt();

					$array = $array->toArray();
					if ( count( $array ) <= $offset ) {
						throw new AFPUserVisibleException( 'outofbounds', $node->position,
							[ $offset, count( $array ) ] );
					}

					$array[$offset] = $value;
					$this->setUserVariable( $varName, new AFPData( AFPData::DARRAY, $array ) );
				}

				return $value;

			case AFPTreeNode::ARRAY_APPEND:
				list( $varName, $value ) = $node->children;

				if ( $this->isBuiltinVar( $varName ) ) {
					throw new AFPUserVisibleException( 'overridebuiltin', $node->position, [ $varName ] );
				}

				$array = $this->getVarValue( $varName );
				if ( $array->getType() !== AFPData::DUNDEFINED ) {
					// If it's a DUNDEFINED, leave it as is
					if ( $array->getType() !== AFPData::DARRAY ) {
						throw new AFPUserVisibleException( 'notarray', $node->position, [] );
					}

					$array = $array->toArray();
					$array[] = $this->evalNode( $value );
					$this->setUserVariable( $varName, new AFPData( AFPData::DARRAY, $array ) );
				}
				return $value;

			case AFPTreeNode::SEMICOLON:
				$lastValue = null;
				// @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach children is array here
				foreach ( $node->children as $statement ) {
					$lastValue = $this->evalNode( $statement );
				}

				return $lastValue;
			default:
				// @codeCoverageIgnoreStart
				throw new AFPException( "Unknown node type passed: {$node->type}" );
				// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * Given a node that we don't need to evaluate, decide what to do with it. The nodes passed in
	 * will usually be discarded by short-circuit evaluation. If we allow it, then we just hoist
	 * the variables assigned in any descendant of the node. Otherwise, we fully evaluate the node.
	 *
	 * @param AFPTreeNode $node
	 */
	private function maybeDiscardNode( AFPTreeNode $node ) {
		if ( $this->mAllowShort ) {
			$this->discardWithHoisting( $node );
		} else {
			$this->evalNode( $node );
		}
	}

	/**
	 * Intended to be used for short-circuit as a solution for T214674.
	 * Given a node, check it and its children; if there are assignments of non-existing variables,
	 * hoist them. In case of index assignment or array append, the old value is always erased and
	 * overwritten with a DUNDEFINED. This is used to allow stuff like:
	 * false & ( var := 'foo' ); var == 2
	 * or
	 * if ( false ) then ( var := 'foo' ) else ( 1 ) end; var == 2
	 * where `false` is something evaluated as false at runtime.
	 *
	 * @note This method doesn't check whether the variable exists in case of index assignments.
	 *   Hence, in `false & (nonexistent[] := 2)`, `nonexistent` would be hoisted without errors.
	 *   However, that would by caught by checkSyntax, so we can avoid checking here: we'd need
	 *   way more context than we currently have.
	 *
	 * @param AFPTreeNode $node
	 */
	private function discardWithHoisting( AFPTreeNode $node ) {
		foreach ( $node->getInnerAssignments() as $name ) {
			if (
				!$this->mVariables->varIsSet( $name ) ||
				$this->mVariables->getVar( $name )->getType() === AFPData::DARRAY
			) {
				$this->setUserVariable( $name, new AFPData( AFPData::DUNDEFINED ) );
			}
		}
	}
}
