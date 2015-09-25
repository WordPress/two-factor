<?php
# Take input from `git diff --no-prefix --unified=0`
# Outputs path/to/file.ext:123-456
# Where 123 is the start line in a diff and 456 is the end line.
# A line appears for each patch occuring in a file.

$current_file = null;

while ( $line = fgets( STDIN ) ) {
	if ( preg_match( '#^\+\+\+ (?P<file_path>.+)#', $line, $matches ) ) {
		$current_file = $matches['file_path'];
		$file_ranges[ $current_file ] = array();
		continue;
	}
	if ( empty( $current_file ) ) {
		continue;
	}
	if ( preg_match( '#^@@ -(\d+)(?:,(\d+))? \+(?P<line_number>\d+)(?:,(?P<line_count>\d+))? @@#', $line, $matches ) ) {
		if ( empty( $matches['line_count'] ) ) {
			$matches['line_count'] = 0;
		}
		$start_line = intval( $matches['line_number'] );
		$end_line = intval( $matches['line_number'] ) + intval( $matches['line_count'] );
		echo "$current_file:$start_line-$end_line\n";
	}
}
