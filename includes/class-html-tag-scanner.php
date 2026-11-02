<?php
/**
 * Read-only tag scanner that reports where the current tag sits in the HTML.
 *
 * @package EmpiricalResponsiveImages
 */

namespace EmpiricalResponsiveImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tag Processor that exposes the byte span of the matched tag.
 *
 * Editing a large page through one Tag Processor is slow: every queued edit is
 * rechecked on each later token. Callers scan with this class without editing,
 * then rewrite each tag on its own and splice it back in by position.
 */
final class HTML_Tag_Scanner extends \WP_HTML_Tag_Processor {
	private const BOOKMARK = 'empirical-responsive-images-span';

	/**
	 * Get the matched tag's start offset and length in bytes.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public function current_tag_span(): ?array {
		if ( ! $this->set_bookmark( self::BOOKMARK ) ) {
			return null;
		}

		$span = $this->bookmarks[ self::BOOKMARK ] ?? null;

		return $span instanceof \WP_HTML_Span ? array( (int) $span->start, (int) $span->length ) : null;
	}
}
