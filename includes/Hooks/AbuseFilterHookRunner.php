<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use Content;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\RCVariableGenerator;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\HookContainer\HookContainer;
use RecentChange;
use Title;
use User;

/**
 * Handle running AbuseFilter's hooks
 * @author DannyS712
 */
class AbuseFilterHookRunner implements
	AbuseFilterAlterVariablesHook,
	AbuseFilterBuilderHook,
	AbuseFilterComputeVariableHook,
	AbuseFilterContentToStringHook,
	AbuseFilterCustomActionsHook,
	AbuseFilterDeprecatedVariablesHook,
	AbuseFilterFilterActionHook,
	AbuseFilterGenerateGenericVarsHook,
	AbuseFilterGenerateTitleVarsHook,
	AbuseFilterGenerateUserVarsHook,
	AbuseFilterGenerateVarsForRecentChangeHook,
	AbuseFilterInterceptVariableHook,
	AbuseFilterShouldFilterActionHook,
	AbuseFilterGetDangerousActionsHook
{
	public const SERVICE_NAME = 'AbuseFilterHookRunner';

	/** @var HookContainer */
	private $hookContainer;

	/**
	 * @param HookContainer $hookContainer
	 */
	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterBuilder( array &$realValues ) {
		return $this->hookContainer->run(
			'AbuseFilter-builder',
			[ &$realValues ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterDeprecatedVariables( array &$deprecatedVariables ) {
		return $this->hookContainer->run(
			'AbuseFilter-deprecatedVariables',
			[ &$deprecatedVariables ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterComputeVariable(
		string $method,
		VariableHolder $vars,
		array $parameters,
		?string &$result
	) {
		return $this->hookContainer->run(
			'AbuseFilter-computeVariable',
			[ $method, $vars, $parameters, &$result ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterContentToString(
		Content $content,
		?string &$text
	) {
		return $this->hookContainer->run(
			'AbuseFilter-contentToString',
			[ $content, &$text ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterFilterAction(
		VariableHolder &$vars,
		Title $title
	) {
		return $this->hookContainer->run(
			'AbuseFilter-filterAction',
			[ &$vars, $title ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterAlterVariables(
		VariableHolder &$vars,
		Title $title,
		User $user
	) {
		return $this->hookContainer->run(
			'AbuseFilterAlterVariables',
			[ &$vars, $title, $user ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterGenerateTitleVars(
		VariableHolder $vars,
		Title $title,
		string $prefix,
		?RecentChange $rc
	) {
		return $this->hookContainer->run(
			'AbuseFilter-generateTitleVars',
			[ $vars, $title, $prefix, $rc ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterGenerateUserVars(
		VariableHolder $vars,
		User $user,
		?RecentChange $rc
	) {
		return $this->hookContainer->run(
			'AbuseFilter-generateUserVars',
			[ $vars, $user, $rc ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterGenerateGenericVars(
		VariableHolder $vars,
		?RecentChange $rc
	) {
		return $this->hookContainer->run(
			'AbuseFilter-generateGenericVars',
			[ $vars, $rc ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterGenerateVarsForRecentChange(
		RCVariableGenerator $generator,
		RecentChange $rc,
		VariableHolder $vars,
		User $contextUser
	) {
		return $this->hookContainer->run(
			'AbuseFilter-generateVarsForRecentChange',
			[ $generator, $rc, $vars, $contextUser ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterInterceptVariable(
		string $method,
		VariableHolder $vars,
		array $parameters,
		&$result
	) {
		return $this->hookContainer->run(
			'AbuseFilter-interceptVariable',
			[ $method, $vars, $parameters, &$result ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterShouldFilterAction(
		VariableHolder $vars,
		Title $title,
		User $user,
		array &$skipReasons
	) {
		return $this->hookContainer->run(
			'AbuseFilterShouldFilterAction',
			[ $vars, $title, $user, &$skipReasons ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterGetDangerousActions( array &$actions ) : void {
		$this->hookContainer->run(
			'AbuseFilterGetDangerousActions',
			[ &$actions ],
			[ 'abortable' => false ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterCustomActions( array &$actions ) : void {
		$this->hookContainer->run(
			'AbuseFilterCustomActions',
			[ &$actions ],
			[ 'abortable' => false ]
		);
	}
}
