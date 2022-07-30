<?php

namespace MediaWiki\Skins\Cosmos;

use Html;
use IContextSource;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Skins\Cosmos\Hook\CosmosHookRunner;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\User\UserFactory;
use MWTimestamp;
use RecentChange;
use TitleValue;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\SelectQueryBuilder;

class CosmosRail {

	/** @var CosmosConfig */
	private $config;

	/** @var CosmosHookRunner */
	private $hookRunner;

	/** @var IContextSource */
	private $context;

	/** @var ILoadBalancer */
	private $dbLoadBalancer;

	/** @var LinkRenderer */
	private $linkRenderer;

	/** @var ServiceOptions */
	private $options;

	/** @var SpecialPageFactory */
	private $specialPageFactory;

	/** @var UserFactory */
	private $userFactory;

	/** @var WANObjectCache */
	private $wanObjectCache;

	/** @var array */
	private $modules;

	/**
	 * @param CosmosConfig $config
	 * @param CosmosHookRunner $hookRunner
	 * @param ILoadBalancer $dbLoadBalancer
	 * @param LinkRenderer $linkRenderer
	 * @param IContextSource $context
	 * @param ServiceOptions $options
	 * @param SpecialPageFactory $specialPageFactory
	 * @param UserFactory $userFactory
	 * @param WANObjectCache $wanObjectCache
	 */
	public function __construct(
		CosmosConfig $config,
		CosmosHookRunner $hookRunner,
		ILoadBalancer $dbLoadBalancer,
		LinkRenderer $linkRenderer,
		IContextSource $context,
		ServiceOptions $options,
		SpecialPageFactory $specialPageFactory,
		UserFactory $userFactory,
		WANObjectCache $wanObjectCache
	) {
		$this->config = $config;
		$this->context = $context;
		$this->dbLoadBalancer = $dbLoadBalancer;
		$this->hookRunner = $hookRunner;
		$this->linkRenderer = $linkRenderer;
		$this->options = $options;
		$this->specialPageFactory = $specialPageFactory;
		$this->userFactory = $userFactory;
		$this->wanObjectCache = $wanObjectCache;

		$this->modules = $this->getModules();

		if ( $this->modules ) {
			$this->context->getOutput()->addModuleStyles( [ 'skins.cosmos.rail' ] );
		}

	}

	/**
	 * @return bool
	 */
	public function hidden(): bool {
		$disabledNamespaces = $this->config->getRailDisabledNamespaces();
		$disabledPages = $this->config->getRailDisabledPages();

		$title = $this->context->getTitle();
		$out = $this->context->getOutput();

		if (
			$title->inNamespaces( $disabledNamespaces ) ||
			(
				$title->isMainPage() &&
				in_array( 'mainpage', $disabledPages )
			) ||
			in_array( $title->getFullText(), $disabledPages ) ||
			(bool)$out->getProperty( 'norail' )
		) {
			return true;
		}

		return false;
	}

	/**
	 * @return string
	 */
	public function buildRail(): string {
		$modules = '';
		foreach ( $this->modules as $module => $data ) {
			if ( $data['header'] ?? false ) {
				$modules .= $this->buildModuleHeader( $data['header'] );
			}

			$isSticky = $data['type'] === 'sticky';
			$modules .= Html::rawElement( 'section', [
				'class' => [
					'railModule' => true,
					'module' => true,
					'rail-sticky-module' => $isSticky,
				] + (array)$data['class']
			], $data['body'] );
		}

		$rail = '';
		if ( $modules ) {
			$rail .= Html::openElement( 'div', [
				'class' => 'CosmosRail',
				'id' => 'CosmosRailWrapper',
			] );

			$rail .= Html::rawElement( 'div', [
				'class' => 'cosmos-rail-inner',
				'id' => 'CosmosRail',
			], $modules ) );

			$rail .= Html::closeElement( 'div' );
		}

		return $rail;
	}

	/**
	 * @param string $label
	 * @return string
	 */
	protected function buildModuleHeader( string $label ): string {
		if ( !$this->context->msg( $label )->isDisabled() ) {
			$label = $this->context->msg( $label )->text();
		}

		$header = Html::element( 'h3', [], $label );

		return $header;
	}


	/**
	 * @return array
	 */
	public function getModules(): array {
		$modules = [];

		if ( $this->hidden() ) {
			return $modules;
		}

		$enableRecentChangesModule = $this->config->getEnabledRailModules()['recentchanges'];
		if ( $enableRecentChangesModule && !empty( $this->getRecentChanges() ) ) {
			$this->buildRecentChangesModule( $modules );
		}

		$this->buildInterfaceModules( $modules );

		$this->hookRunner->onCosmosRail( &$modules, $this->context->getSkin() );

		return $modules;
	}

	/**
	 * @param array &$modules
	 */
	protected function buildInterfaceModules( array &$modules ) {
		$interfaceRailModules = $this->config->getEnabledRailModules()['interface'];

		$interfaceModules = $interfaceRailModules[0] ?? $interfaceRailModules;

		foreach ( (array)$interfaceModules as $message => $type ) {
			if ( $type && !$this->context->msg( $message )->isDisabled() ) {
				$modules['interface-' . $message] = [
					'body' => $this->context->msg( $message )->parse(),
					'class' => 'interface-module',
					'type' => $type,
				];
			}
		}
	}

	/**
	 * @param array &$modules
	 */
	protected function buildRecentChangesModule( array &$modules ) {
		$type = $this->config->getEnabledRailModules()['recentchanges'];

		$modules['recentchanges'] = [
			'class' => 'recentchanges-module',
			'header' => 'recentchanges',
			'type' => $type,
		];

		$body = '';
		foreach ( $this->getRecentChanges() as $recentChange ) {
			// Open list item for recent change
			$body .= Html::openElement( 'li' );

			$body .= Html::openElement( 'div', [ 'class' => 'cosmos-recentChanges-page' ] );

			// Create a link to the edited page
			$body .= $this->linkRenderer->makeKnownLink(
				new TitleValue( (int)$recentChange['namespace'], $recentChange['title'] )
			);

			$body .= Html::closeElement( 'div' );

			$body .= Html::openElement( 'div', [ 'class' => 'cosmos-recentChanges-info' ] );

			// Create a link to the user who edited it
			$performer = $recentChange['performer'];
			if ( !$performer->isRegistered() ) {
				$linkTarget = new TitleValue(
					NS_SPECIAL,
					$this->specialPageFactory->getLocalNameFor( 'Contributions', $performer->getName() )
				);
			} else {
				$linkTarget = new TitleValue( NS_USER, $performer->getTitleKey() );
			}

			$body .= $this->linkRenderer->makeLink( $linkTarget, $performer->getName() );

			// Display how long ago it was edited
			$body .= ' • ';
			$language = $this->context->getSkin()->getLanguage();

			$body .= $language->getHumanTimestamp(
				MWTimestamp::getInstance( $recentChange['timestamp'] )
			);

			$body .= Html::closeElement( 'div' );

			// Close the list item
			$body .= Html::closeElement( 'li' );
		}

		$modules['recentchanges']['body'] = $body;
	}

	/**
	 * @return array
	 */
	protected function getRecentChanges() {
		$cacheKey = $this->wanObjectCache->makeKey( 'cosmos_recentChanges', 4 );
		$recentChanges = $this->wanObjectCache->get( $cacheKey );

		if ( empty( $recentChanges ) ) {
			$dbr = $this->dbLoadBalancer->getConnectionRef( DB_REPLICA );

			$res = $dbr->newSelectQueryBuilder()
				->table( 'recentchanges' )
				->fields( [
					'rc_actor',
					'rc_namespace',
					'rc_title',
					'rc_timestamp',
				] )
				->where( [
					'rc_type' => RecentChange::parseToRCType( [ 'new', 'edit' ] ),
					'rc_bot' => 0,
					'rc_deleted' => 0,
				] )
				->orderBy( 'rc_timestamp', SelectQueryBuilder::SORT_DESC )
				->limit( 4 )
				->offset( 0 )
				->caller( __METHOD__ )
				->fetchResultSet();

			$recentChanges = [];
			foreach ( $res as $row ) {
				$recentChanges[] = [
					'performer' => $this->userFactory->newFromActorId( $row->rc_actor ),
					'timestamp' => $row->rc_timestamp,
					'namespace' => $row->rc_namespace,
					'title' => $row->rc_title,
				];
			}

			$this->wanObjectCache->set( $cacheKey, $recentChanges, 30 );
		}

		return $recentChanges;
	}
}
