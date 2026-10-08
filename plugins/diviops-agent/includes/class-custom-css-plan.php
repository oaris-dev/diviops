<?php
/** Pure, bounded named-block planner. No storage access; shared by the bounded CSS service. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DiviOps_Custom_CSS_Plan {
	const MAX_BYTES = 262144;
	const MARKER = 'diviops:css:';

	/** Returns a plan or a refusal; callers must separately establish storage/permission safety. */
	public static function upsert( string $document, string $name, string $css ): array {
		if ( 1 !== preg_match( '/\A[a-z][a-z0-9-]{0,63}\z/', $name ) ) {
			return self::error( 'invalid_name' );
		}
		if ( strlen( $document ) > self::MAX_BYTES || strlen( $css ) > self::MAX_BYTES ) {
			return self::error( 'too_large' );
		}
		if ( false !== strpos( $css, self::MARKER ) || '' === trim( $css ) ) {
			return self::error( 'invalid_block' );
		}
		$parsed = self::scan( $document, true );
		if ( ! $parsed['ok'] ) { return $parsed; }
		$body = self::scan( $css, false );
		if ( ! $body['ok'] ) { return $body; }
		$replacement = '/* diviops:css:' . $name . ":start */\n" . $css . "\n/* diviops:css:" . $name . ':end */';
		$range = $parsed['blocks'][ $name ] ?? null;
		if ( null === $range ) {
			$offset = strlen( $document );
			$before = '';
			$replacement = ( '' === $document || "\n" === substr( $document, -1 ) ? '' : "\n" ) . $replacement;
		} else {
			$offset = $range[0];
			$before = substr( $document, $offset, $range[1] - $offset );
		}
		$after = substr_replace( $document, $replacement, $offset, strlen( $before ) );
		if ( strlen( $after ) > self::MAX_BYTES ) { return self::error( 'too_large' ); }
		return [
			'ok' => true, 'name' => $name, 'noop' => $after === $document,
			'before_checksum' => 'sha256:' . hash( 'sha256', $document ),
			'after_checksum' => 'sha256:' . hash( 'sha256', $after ),
			'edit' => [ 'byte_offset' => $offset, 'before' => $before, 'after' => $replacement ],
			'css' => $after,
		];
	}

	/** Lexical boundary check only: not a CSS grammar, selector or cascade validator. */
	private static function scan( string $text, bool $markers ): array {
		if ( 1 !== preg_match( '//u', $text ) || false !== strpos( $text, "\0" ) ) {
			return self::error( 'invalid_text' );
		}
		// STYLE breakout or a terminal prefix that concatenated CSS could complete.
		if ( preg_match( '~</style(?=[\s/>]|$)|<(?:/(?:s(?:t(?:y(?:l(?:e)?)?)?)?)?)?\z~i', $text ) ) {
			return self::error( 'style_boundary' );
		}
		$blocks = []; $open = null; $stack = []; $length = strlen( $text ); $reserved = 0;
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $text[$i];
			if ( '\\' === $c ) {
				if ( ++$i >= $length ) { return self::error( 'incomplete_css' ); }
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
				for ( $i++; $i < $length; $i++ ) {
					if ( '\\' === $text[$i] ) {
						$i++;
						if ( "\r" === ( $text[$i] ?? '' ) && "\n" === ( $text[$i + 1] ?? '' ) ) { $i++; }
						continue;
					}
					if ( $quote === $text[$i] ) { break; }
					if ( "\n" === $text[$i] || "\r" === $text[$i] || "\f" === $text[$i] ) { return self::error( 'incomplete_css' ); }
				}
				if ( $i >= $length ) { return self::error( 'incomplete_css' ); }
				continue;
			}
			if ( '/' === $c && '*' === ( $text[$i + 1] ?? '' ) ) {
				$end = strpos( $text, '*/', $i + 2 );
				if ( false === $end ) { return self::error( 'incomplete_css' ); }
				$comment = substr( $text, $i, $end + 2 - $i );
				if ( false !== strpos( $comment, self::MARKER ) ) {
					if ( ! $markers || [] !== $stack || ! preg_match( '~\A/\* diviops:css:([a-z][a-z0-9-]{0,63}):(start|end) \*/\z~', $comment, $match ) ) {
						return self::error( 'ambiguous_markers' );
					}
					$reserved++;
					if ( 'start' === $match[2] ) {
						if ( null !== $open || isset( $blocks[ $match[1] ] ) ) { return self::error( 'ambiguous_markers' ); }
						$open = [ $match[1], $i ];
					} else {
						if ( null === $open || $open[0] !== $match[1] ) { return self::error( 'ambiguous_markers' ); }
						$blocks[ $match[1] ] = [ $open[1], $end + 2 ]; $open = null;
					}
				}
				$i = $end + 1; continue;
			}
			if ( false !== strpos( '{[(', $c ) ) { $stack[] = $c; }
			if ( false !== strpos( '}])', $c ) ) {
				$pairs = [ '}' => '{', ']' => '[', ')' => '(' ];
				if ( array_pop( $stack ) !== $pairs[$c] ) { return self::error( 'incomplete_css' ); }
			}
		}
		if ( null !== $open || $reserved !== substr_count( $text, self::MARKER ) ) { return self::error( 'ambiguous_markers' ); }
		if ( [] !== $stack ) { return self::error( 'incomplete_css' ); }
		return [ 'ok' => true, 'blocks' => $blocks ];
	}

	private static function error( string $code ): array {
		return [ 'ok' => false, 'error' => $code ];
	}
}
