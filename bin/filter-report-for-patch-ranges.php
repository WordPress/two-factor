<?php
# Take unix/emacs reports as STDIN, such as:
#   foo/js/bar.js:503:55: Missing radix parameter.
# And takes a single argument pointing to the file containing the output
# of parse-diff-ranges.php, such as:
#   path/to/file.ext:123-456
# And then echos the line from STDIN if the path (here, foo/js/bar.hs) exists
# in the latter, and the line number (here 503) is among the ranges represented.
# If there are any matches, this script will return an exit code 1.
# If there are no matches, the script will return exit code 0.

if ( empty( $argv[1] ) ) {
	echo 'Missing argument for file containing output of parse-diff-ranges.php.';
	exit( 2 );
}
if ( ! file_exists( $argv[1] ) ) {
	echo 'Argument for file containing output of parse-diff-ranges.php does not exist.';
	exit( 2 );
}

$matched_patch_count = 0;

$parsed_diff_ranges = array();
foreach ( explode( "\n", trim( file_get_contents( $argv[1] ) ) ) as $line ) {
	if ( preg_match( '/^(?P<file_path>.+):(?P<start_line>\d+)-(?P<end_line>\d+)$/', $line, $matches ) ) {
		$file_path = realpath( $matches['file_path'] );
		if ( ! array_key_exists( $file_path, $parsed_diff_ranges ) ) {
			$parsed_diff_ranges[ $file_path ] = array();
		}
		$parsed_diff_ranges[ $file_path ][] = array(
			'start_line' => intval( $matches['start_line'] ),
			'end_line' => intval( $matches['end_line'] ),
		);
	}
}

while ( $line = fgets( STDIN ) ) {
	if ( ! preg_match( '#^(?P<file_path>.+):(?P<line_number>\d+):\d+:.+$$#', $line, $matches ) ) {
		continue;
	}
	$file_path = realpath( $matches['file_path'] );
	if ( ! array_key_exists( $file_path, $parsed_diff_ranges ) ) {
		continue;
	}
	$line_number = intval( $matches['line_number'] );
	$matched = false;
	foreach ( $parsed_diff_ranges[ $file_path ] as $range ) {
		if ( $line_number >= $range['start_line'] && $line_number <= $range['end_line'] ) {
			$matched = true;
			break;
		}
	}
	if ( ! $matched ) {
		continue;
	}

	$matched_patch_count += 1;
	echo $line;
}

if ( $matched_patch_count > 0 ) {
	exit( 1 );
} else {
	exit( 0 );
}
