<?php

declare(strict_types=1);

use ManufacturingErp\{Database,GroupPublication,Identity,Value};

/** Two connected workers wait for the parent to release them onto the same business operation. */
function publishConcurrently(array $first,array $second): array
{
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY==='Windows') {
        array_push($command,'-d','extension=pdo_mysql');
    }
    $command[] = __FILE__;
    $children = [];
    try {
        foreach ([$first,$second] as $request) {
            $child = proc_open($command,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
            if (!is_resource($child)) {
                throw new RuntimeException('Could not launch independent database client');
            }
            $children[] = [$child,$pipes];
            fwrite($pipes[0],Value::json($request)."\n");
            fflush($pipes[0]);
            if (trim((string)fgets($pipes[1]))!=='ready') {
                throw new RuntimeException('Database worker failed to open: '.stream_get_contents($pipes[2]));
            }
        }
        foreach ($children as [,$pipes]) {
            fwrite($pipes[0],"publish\n");
            fflush($pipes[0]);
        }
        $results = [];
        foreach ($children as [,$pipes]) {
            $line = fgets($pipes[1]);
            if (!$line) {
                throw new RuntimeException('Database worker failed to publish: '.stream_get_contents($pipes[2]));
            }
            $results[] = Value::fromJson($line);
        }
        return $results;
    } finally {
        foreach ($children as [$child,$pipes]) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($child);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__) {
    require __DIR__.'/bootstrap.php';
    $request = Value::fromJson(fgets(STDIN));
    $db = Database::connect();
    $scope = (new Identity($db))->scope($request['user'],$request['org']);
    fwrite(STDOUT,"ready\n");
    fflush(STDOUT);
    if (trim((string)fgets(STDIN))==='publish') {
        $results = (new GroupPublication($db,$scope))->publish($request['key'],$request['scenario'],$request['revision'],$request['selected'],$request['end']);
        echo Value::json($results).PHP_EOL;
    }
}
