<?php
/**
 * Simple MySQL dump converter from first version
 *
 * NOTE: it uses regular expressions, so it works only with original MySQL dump format with single-quoted comments
 *
 * NOTE: all reports will be created with default_history_time, so they will use whatever time is configured in my.cnf
 *
 * Usage:
 * > php scripts/convert_mysqldump.php dump.sql
 *
 * @author Oleg Efimov <efimovov@gmail.com>
 */

/* ===== Check arguments ===== */

if ($GLOBALS['argc'] !== 2) {
    showUsageAndExit(1);
}

$filename = $GLOBALS['argv'][1];
if (!is_readable($filename)) {
    showUsageAndExit(2);
}

/* ===== Extract CREATE TABLE queries and convert them ===== */

$sql = file_get_contents($filename);

$result = preg_match_all("/CREATE TABLE.*?ENGINE=PINBA.*?COMMENT='.*?';/s", $sql, $matches);
if ($result === false) {
    showUsageAndExit(3);
}

foreach ($matches[0] as $match) {
    convertTableSql($sql, $match);
}

echo $sql;
exit;

/* ===== Functions ===== */

function showUsageAndExit($exit_code)
{
    echo "Usage:\n";
    echo "> php scripts/convert_mysqldump.php dump.sql\n";
    exit($exit_code);
}

function convertTableSql(&$full_sql, $create_table_sql)
{
    $result = preg_match("/COMMENT='(.*?)';/", $create_table_sql, $matches);
    if ($result === false) {
        return;
    }

    $comment = $matches[1];

    if (tryConvertDefaultTableSql($full_sql, $create_table_sql, $comment)) {
        return;
    }

    if (strpos($comment, "report") === 0) {
        convertDefaultReportsSql($full_sql, $create_table_sql, $comment);
        return;
    }
}

function tryConvertDefaultTableSql(&$full_sql, $create_table_sql, $comment)
{
    $filename = "default_tables/{$comment}.sql";
    if (!is_readable($filename)) {
        return false;
    }

    $default_table_sql = file_get_contents($filename);

    if (!preg_match("/CREATE TABLE.*?\((.*)\)[^)]*COMMENT='(.*?)';/s", $default_table_sql, $matches)) {
        showUsageAndExit(4);
    }

    $default_table_sql_body = $matches[1];
    $default_table_sql_comment = $matches[2];

    if (!preg_match("/(CREATE TABLE.*?\()(.*)(\)[^)]*COMMENT=')(?:.*?)(';)/s", $create_table_sql, $matches)) {
        showUsageAndExit(5);
    }

    $create_table_sql_converted = $matches[1] . $default_table_sql_body . $matches[3] . $default_table_sql_comment . $matches[4];

    $full_sql = str_replace($create_table_sql, $create_table_sql_converted, $full_sql);

    return true;
}

function convertDefaultReportsSql(&$full_sql, $create_table_sql, $comment)
{
    {
        static $legacy_fields = [
            'host' => 'hostname',
            'server' => 'server_name',
            'script' => 'script_name',
        ];

        static $fields_types = [
            'host' => "varchar(32)",
            'server' => "varchar(64)",
            'script' => "varchar(128)",
            'status' => "int(11)",
            'schema' => "varchar(16)",
        ];

        static $fields_config = [
            'report1'  => ['script'],
            'report2'  => ['server'],
            'report3'  => ['host',],
            'report4'  => ['server', 'script'],
            'report5'  => ['host', 'script'],
            'report6'  => ['host', 'server'],
            'report7'  => ['host', 'server', 'script'],
            'report8'  => ['status'],
            'report9'  => ['script', 'status'],
            'report10' => ['server', 'status'],
            'report11' => ['host', 'status'],
            'report12' => ['host', 'script', 'status'],
            'report13' => ['schema'],
            'report14' => ['script', 'schema'],
            'report15' => ['server', 'schema'],
            'report16' => ['host', 'schema'],
            'report17' => ['host', 'script', 'schema'],
            'report18' => ['host', 'status', 'schema'],
        ];

        static $sql_body = "  `req_count` int(10) unsigned NOT NULL,
  `req_per_sec` float NOT NULL,
  `req_time_total` float NOT NULL,
  `req_time_per_sec` float NOT NULL,
  `ru_utime_total` float NOT NULL,
  `ru_utime_per_sec` float NOT NULL,
  `ru_stime_total` float NOT NULL,
  `ru_stime_per_sec` float NOT NULL,
  `traffic_total` bigint(20) unsigned NOT NULL,
  `traffic_per_sec` float NOT NULL,
  `memory_footprint` bigint(20) NOT NULL";
    }

    if (!preg_match("/(CREATE TABLE.*?\()(.*)(\)[^)]*COMMENT=')(?:.*?)(';)/s", $create_table_sql, $matches)) {
        showUsageAndExit(6);
    }

    $parts = explode(":", $comment);
    if (!isset($fields_config[$parts[0]])) {
        showUsageAndExit(7);
    }

    $percentiles = [
        'list' => [50],
    ];

    $filters = [];
    if (!empty($parts[2])) {
        $filters = explode(",", $parts[2]);
        foreach ($filters as $key => $filter) {
            $filter_parts = explode("=", $filter);

            if ($filter_parts[0] === "histogram_max_time") {
                $percentiles['max_time'] = $filter_parts[1];

                unset($filters[$key]);
                continue;
            }

            $filter_parts[1] = str_replace("tag.", "+", $filter_parts[1]);
            $filters[$key] = "{$filter_parts[0]}={$filter_parts[1]}";
        }
    }

    $request_fields_sql = [];
    foreach ($fields_config[$parts[0]] as $request_field) {
        $name = $request_field;
        if (isset($legacy_fields[$request_field])) {
            $name = $legacy_fields[$request_field];
        }

        $field_type = "varchar(64)";
        if (isset($fields_types[$request_field])) {
            $field_type = $fields_types[$request_field];
        }

        $request_fields_sql[] = "  `{$name}` {$field_type} NOT NULL,";
    }
    $request_fields_sql = implode("\n", $request_fields_sql);

    $percentiles_fields_sql = "";
    if (!empty($percentiles['list'])) {
        $percentiles['list'] = array_map(function ($item) {
            return "p{$item}";
        }, $percentiles['list']);

        $percentiles_fields_sql = [];
        foreach ($percentiles['list']as $percentile) {
            $percentiles_fields_sql[] = "  `{$percentile}` float NOT NULL";
        }
        $percentiles_fields_sql = implode(",\n", $percentiles_fields_sql);

        $percentiles_fields_sql = ",\n{$percentiles_fields_sql}";
    }

    $create_table_sql_converted = $matches[1] . "\n" . $request_fields_sql . "\n" . $sql_body . $percentiles_fields_sql . "\n"
                                . $matches[3] . generateV2Comment("request", $fields_config[$parts[0]], $percentiles, $filters) . $matches[4];

    $full_sql = str_replace($create_table_sql, $create_table_sql_converted, $full_sql);
}

function generateV2Comment($report_type, $request_fields, $percentiles, $filters)
{
    $request_fields = array_map(function ($item) { return "~{$item}"; }, $request_fields);
    $request_fields = implode(",", $request_fields);

    if (empty($percentiles)) {
        $percentiles = "no_percentiles";
    } else {
        if (empty($percentiles['max_time'])) {
            $percentiles['max_time'] = 60;
        }
        $percentiles_hv = "hv=0:10000:" . ($percentiles['max_time'] * 1000);

        $percentiles = $percentiles_hv . (!empty($percentiles['list']) ? "," . implode(",", $percentiles['list']) : "");
    }

    if (empty($filters)) {
        $filters = "no_filters";
    } else {
        $filters = implode(",", $filters);
    }

    return "v2/{$report_type}/default_history_time/{$request_fields}/{$percentiles}/{$filters}";
}
