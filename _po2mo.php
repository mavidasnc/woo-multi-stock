<?php
/**
 * Minimal .po → .mo compiler.
 * Usage: php _po2mo.php
 */
$po_file = __DIR__ . '/languages/woo-multi-stock-it_IT.po';
$mo_file = __DIR__ . '/languages/woo-multi-stock-it_IT.mo';

$po = file_get_contents( $po_file );

// Parse msgid / msgstr pairs (single-line values only).
$entries = array();
if ( preg_match_all(
	'/^msgid\s+"((?:[^"\\\\]|\\\\.)*)"\s*\nmsgstr\s+"((?:[^"\\\\]|\\\\.)*)"/m',
	$po,
	$matches,
	PREG_SET_ORDER
) ) {
	foreach ( $matches as $m ) {
		$key = stripcslashes( $m[1] );
		$val = stripcslashes( $m[2] );
		if ( '' !== $key && '' !== $val ) {
			$entries[ $key ] = $val;
		}
	}
}

if ( empty( $entries ) ) {
	echo "No entries found.\n";
	exit( 1 );
}

ksort( $entries );
$num = count( $entries );

$keys   = array();
$values = array();
foreach ( $entries as $k => $v ) {
	$keys[]   = $k . "\x00";
	$values[] = $v . "\x00";
}

// Offsets: header(28) + key-table(8*num) + val-table(8*num)
$key_offset = 28 + 8 * $num * 2;
$val_offset = $key_offset;
foreach ( $keys as $k ) {
	$val_offset += strlen( $k );
}

$key_data = implode( '', $keys );
$val_data = implode( '', $values );

$key_table = '';
$pos = $key_offset;
foreach ( $keys as $k ) {
	$key_table .= pack( 'VV', strlen( $k ) - 1, $pos );
	$pos       += strlen( $k );
}

$val_table = '';
$pos = $val_offset;
foreach ( $values as $v ) {
	$val_table .= pack( 'VV', strlen( $v ) - 1, $pos );
	$pos       += strlen( $v );
}

$mo = pack( 'V*', 0x950412de, 0, $num, 28, 28 + 8 * $num, 0, 0 )
	. $key_table
	. $val_table
	. $key_data
	. $val_data;

file_put_contents( $mo_file, $mo );
echo 'OK — ' . $num . ' strings → ' . strlen( $mo ) . " bytes\n";
