#!/usr/bin/env php
<?php
// Keep the static-analysis workflow reproducible without mutable action tags.
$workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/analysis.yml');
if (!is_string($workflow)) {
    throw new RuntimeException('Unable to read the static-analysis workflow.');
}
$match_count = preg_match_all('/uses:\s+([^\s@]+)@([^\s#]+)/', $workflow, $matches, PREG_SET_ORDER);
if ($match_count === false || $match_count === 0) {
    throw new RuntimeException('Expected external actions in the static-analysis workflow.');
}
foreach ($matches as $match) {
    if (preg_match('/^[a-f0-9]{40}$/D', $match[2]) !== 1) {
        throw new RuntimeException('Action ' . $match[1] . ' must use an immutable full commit SHA.');
    }
}
echo "Static-analysis action pin checks passed.\n";
