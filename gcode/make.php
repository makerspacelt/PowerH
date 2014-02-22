#!/usr/bin/php
<?php


$pcb2gcode = '/usr/bin/pcb2gcode';
if (!is_file($pcb2gcode)) die('Can`t find '.$pcb2gcode."\n");


switch (true) {
	case ($argc==2 && $argv[1]=='clean'):
		shell_exec('rm *.ngc *.svg *.png 2>/dev/null');
		break;
	case ($argc==2 && $argv[1]=='debug'):
		make();
		break;
	default:
		make();
		shell_exec('rm outline.ngc back.ngc *.svg *.png 2>/dev/null');
}

function make()
{
	generate_gcode();
	fix_gcode();
}
function generate_gcode()
{
	global $pcb2gcode;
	shell_exec($pcb2gcode);
}
function fix_gcode() {
	$outline = fixOutline(file('outline.ngc'));
	$spot = fixDrill(file('drill.ngc'));
	$back = fixEngrave(file('back.ngc'));
	//printLines($drill);
	writeFile('engrave.ngc', array_merge(array(
		'G21',
		'G94',
	),$outline, $spot, $back));

	$drill = fixDrill(file('drill.ngc'), -2.1);
	//printLines($drill);
	writeFile('drill.ngc', array_merge(array(
		'T2',
		'G21',
		'G94',
	),$drill));
}


function fixOutline($lines)
{
	$lines = array_merge(
		array(
			'(------------------------)',
			'( Outline                )',
			'(------------------------)',
		),
		$lines
	);
	// remove T, M and S commands
	$lines = removeByRegex($lines, '/^[TMS]/');
	// convert numberd to metric
	$lines = imp2metric($lines);
	// remove commands not supported by GRBL
	$lines = removeNonGRBL($lines);

	return $lines;
}
function fixDrill($lines, $force_drill_depth = false)
{
	$lines = array_merge(
		array(
			'(------------------------)',
			'( Drill                  )',
			'(------------------------)',
		),
		$lines
	);
	// remove too long comments
	$lines = removeLongComments($lines, 40);
	// remove T, M and S commands
	$lines = removeByRegex($lines, '/^[TMS]/');
	// convert numberd to metric
	$lines = imp2metric($lines);
	// simplify G81
	$lines = dummyfyG81($lines, $force_drill_depth);
	// remove commands not supported by GRBL
	$lines = removeNonGRBL($lines);

	return $lines;
}
function fixEngrave($lines)
{
	$lines = array_merge(
		array(
			'(------------------------)',
			'( Engrave                )',
			'(------------------------)',
		),
		$lines
	);
	// remove T, M and S commands
	$lines = removeByRegex($lines, '/^[TMS]/');
	// convert numberd to metric
	$lines = imp2metric($lines);
	// remove commands not supported by GRBL
	$lines = removeNonGRBL($lines);

	return $lines;
}




function printLines($lines, $echo = true)
{
	$print_empty = true;
	$str = '';
	foreach ($lines as $line) {
		if (empty($line) && !$print_empty)
			continue;
		$print_empty = (!empty($line));
		if ($echo) {
			echo trim($line)."\n";
		} else {
			$str .= trim($line)."\n";
		}
	}
	return $str;
}
function writeFile($filename, $lines)
{
	return file_put_contents($filename, printLines($lines, false));
}
function parseLine($line)
{
	$result = array();
	preg_match_all("/([A-Z])([0-9.-]+)/is",$line,$matches);
	foreach ($matches[0] as $k => $v) {
		$result[$matches[1][$k]] = $matches[2][$k];
	}
	return $result;
}
function makeLine($arr)
{
	$line = '';
	foreach ($arr as $letter => $number) {
		if ($letter == 'G') {
			$line .= sprintf("%s%02d ",$letter,$number);
		} else {
			$line .= sprintf("%s%.3f ",$letter,$number);
		}
	}
	return $line;
}
function dummyfyG81($lines, $force_drill_depth)
{
	$old_z = 2.5; // sane default?
	$out = array();
	foreach ($lines as $k => $line) {
		$line_data = parseLine($line);
		if ( isset($line_data['Z']) && (!isset($line_data['G']) || $line_data['G'] != 81)) {
			$old_z = $line_data['Z'];
		}
		switch (true) {
			case ( isset($line_data['G']) && $line_data['G'] == 81 ):
				$in_mode = true;
				// get values from G81
				$ready_z = (isset($line_data['R']))?$line_data['R']:$old_z;
				$drill_feed = (isset($line_data['F']))?$line_data['F']:false;
				$drill_z = ($force_drill_depth)?$force_drill_depth:$line_data['Z'];
				// no break so it will make first drilling
			case ( isset($line_data['X']) || isset($line_data['Y']) ):
				$out[] = '( '.$line.' )';
				if ($in_mode) {
					$out[] = makeLine(array('G'=>0, 'Z'=>$old_z));
					$goto = array('G'=>0);
					if (isset($line_data['X'])) $goto['X'] = $line_data['X'];
					if (isset($line_data['Y'])) $goto['Y'] = $line_data['Y'];
					$out[] = makeLine($goto);
					$out[] = makeLine(array('G'=>0, 'Z'=>$ready_z));
					$goto = array('G'=>1, 'Z'=>$drill_z);
					if ($drill_feed) $goto['F'] = $drill_feed;
					$out[] = makeLine($goto);
					$out[] = makeLine(array('G'=>0, 'Z'=>$old_z));
				}
				break;
			case (!empty($line_data['G'])):
				$in_mode = false;
				// no break so it will do default after
			default:
				$out[] = $line;
		}

	}
	return $out;
}
function removeLongComments($lines, $len)
{
	$out = array();
	foreach ($lines as $k => $line) {
		$line_data = parseLine($line);
		if (!empty($line) && $line[0] == '(' && strlen($line) >= $len)
			continue;
		$out[] = $line;
	}
	return $out;
}
function removeNonGRBL($lines)
{
	$out = array();
	foreach ($lines as $k => $line) {
		$line_data = parseLine($line);
		if (isset($line_data['G']) && !in_array((int)$line_data['G'],array(0,1,2,3,17,18,19,20,21,54,55,56,57,58,59,90,91,93,94)))
			continue;
		else if (isset($line_data['M']) && !in_array((int)$line_data['M'],array(0,1,2,3,4,5,8,9)))
			continue;
		else if (isset($line_data['G']) && in_array((int)$line_data['G'],array(21,22,94)))
			continue;

		$out[] = $line;
	}
	return $out;
}
function removeByRegex($lines, $pattern)
{
	$out = array();
	foreach ($lines as $k => $line) {
		if (!preg_match($pattern,$line)) {
			$out[] = trim($line);
		} else {
			//$out[] = '(RM: '.trim($line);
		}
	}
	return $out;
}

function imp2metric($lines)
{
	$out = array();
	foreach ($lines as $k => $line) {
		switch (true) {
			case (preg_match("/^G20/",$line)):
				$out[] = 'G21 (metric!)';
				break;
			case (preg_match("/[XYZF]/",$line)):
				$out[] = preg_replace_callback("/([XYZFR])([0-9.-]+)(\s?)/is", "scaleBy", $line);
				break;
			default:
				$out[] = $line;
		}
	}
	return $out;
}
function scaleBy($match, $factor = 25.4)
{
	$letter = $match[1];
	$number = (float)$match[2];
	$result = $number*$factor;
	$space = $match[3];
	return $letter.round($result,3).$space;
}






