<?php
/** Static evidence only; exact version membership remains the admission gate. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class DiviOps_Provider_Support {
	const RANK_MATH = [ '1.0.279', '1.0.280' ];
	const SCF = [ '6.9.4', '6.9.5' ];
	const RANK_MATH_NATIVE = [ '1.0.279', '1.0.280' ];
	const SCF_NATIVE = [ '6.9.4', '6.9.5' ];
	public static function evidence( string $provider ): array {
		if ( ! in_array( $provider, [ 'rank_math', 'scf' ], true ) ) { throw new InvalidArgumentException( 'Unsupported evidence provider.' ); }
		$versions = 'rank_math' === $provider ? self::RANK_MATH : self::SCF;
		return [ 'supported' => $versions, 'source_assessed' => $versions,
			'native_verified' => 'rank_math' === $provider ? self::RANK_MATH_NATIVE : self::SCF_NATIVE, 'policy' => 'exact_reviewed_versions',
			'verification_scope' => 'rank_math' === $provider
				? 'Bounded metadata, indexing and recovery fixtures; not arbitrary extensions or filters.'
				: 'One existing text field: upgrade, write, native save/reopen and restoration; not generic form-save equivalence.' ];
	}
}
