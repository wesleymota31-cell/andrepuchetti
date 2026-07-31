<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '../agenda-visual.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target);
exit;
