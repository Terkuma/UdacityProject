<?php
/**
 * Template search engine.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateSearch
 *
 * Provides instant search across Meta templates with multi-field
 * matching and filter composition.
 */
final class TemplateSearch {

	/** @var TemplateRepository */
	private TemplateRepository $repository;

	/** @var TemplateCache */
	private TemplateCache $cache;

	public function __construct( TemplateRepository $repository, TemplateCache $cache ) {
		$this->repository = $repository;
		$this->cache      = $cache;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Execute a search query.
	 *
	 * Supported $filters keys:
	 *   query        string   Full-text against name + body.
	 *   category     string
	 *   language     string
	 *   status       string|array
	 *   quality      string
	 *   recently_used bool
	 *   never_used   bool
	 *   per_page     int      Default 25.
	 *   page         int      Default 1.
	 *   orderby      string
	 *   order        string
	 *
	 * @param string               $query
	 * @param array<string, mixed> $filters
	 * @return array{ rows: array<int, object>, total: int, page: int, per_page: int, pages: int }
	 */
	public function search( string $query, array $filters = [] ): array {
		$args = $filters;

		if ( '' !== trim( $query ) ) {
			$args['search'] = $query;
		}

		$per_page = max( 1, (int) ( $args['per_page'] ?? 25 ) );
		$page     = max( 1, (int) ( $args['page']     ?? 1  ) );

		$args['per_page'] = $per_page;
		$args['page']     = $page;

		// Build cache key from args.
		$cache_key = 'search_' . md5( wp_json_encode( $args ) );
		$cached    = $this->cache->get_template_list( md5( wp_json_encode( $args ) ) );
		if ( null !== $cached ) {
			return $cached;
		}

		$result = $this->repository->get_all( $args );

		$pages = $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 0;

		$out = [
			'rows'     => $result['rows'],
			'total'    => $result['total'],
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
		];

		$this->cache->set_template_list( md5( wp_json_encode( $args ) ), $out, 120 );

		return $out;
	}

	/**
	 * Return quick-suggest results (name only, lightweight).
	 *
	 * @param string $query
	 * @param int    $limit
	 * @return array<int, array{ id: int, template_name: string, category: string, language: string, status: string }>
	 */
	public function suggest( string $query, int $limit = 10 ): array {
		$result = $this->repository->get_all( [
			'search'   => $query,
			'per_page' => $limit,
			'page'     => 1,
			'status'   => TemplateRepository::STATUS_APPROVED,
			'orderby'  => 'usage_count',
			'order'    => 'DESC',
		] );

		return array_map(
			static fn( object $t ) => [
				'id'            => (int) $t->id,
				'template_name' => $t->template_name,
				'category'      => $t->category,
				'language'      => $t->language,
				'status'        => $t->status,
			],
			$result['rows']
		);
	}

	/**
	 * Return templates grouped by category.
	 *
	 * @param string $status Filter by status. Empty = all.
	 * @return array<string, array<int, object>>
	 */
	public function get_by_category_grouped( string $status = '' ): array {
		$args = [ 'per_page' => 500, 'page' => 1 ];
		if ( $status ) {
			$args['status'] = $status;
		}
		$rows   = $this->repository->get_all( $args )['rows'];
		$groups = [];

		foreach ( $rows as $row ) {
			$cat = $row->category ?? 'OTHER';
			if ( ! isset( $groups[ $cat ] ) ) {
				$groups[ $cat ] = [];
			}
			$groups[ $cat ][] = $row;
		}

		return $groups;
	}

	/**
	 * Return templates grouped by language.
	 *
	 * @param string $status Filter by status. Empty = all.
	 * @return array<string, array<int, object>>
	 */
	public function get_by_language_grouped( string $status = '' ): array {
		$args = [ 'per_page' => 500, 'page' => 1 ];
		if ( $status ) {
			$args['status'] = $status;
		}
		$rows   = $this->repository->get_all( $args )['rows'];
		$groups = [];

		foreach ( $rows as $row ) {
			$lang = $row->language ?? 'en';
			if ( ! isset( $groups[ $lang ] ) ) {
				$groups[ $lang ] = [];
			}
			$groups[ $lang ][] = $row;
		}

		return $groups;
	}
}
