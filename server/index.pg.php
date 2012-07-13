<?php

$dbHost = "localhost";
$dbUser = "postgres";
$dbPass = "test";
$dbName = "postgis20";

// require_once("config.php");

$db = pg_connect("host=$dbHost port=5432 dbname=$dbName user=$dbUser password=$dbPass");

//*****************************************************************************

define("OFFSET_X", 0);
define("OFFSET_Y", 0);
define("SCALE_Z",  1);

define("TILE_SIZE", 256);
define("MAX_ZOOM",  18);

//*****************************************************************************

function geoToPixel($lat, $lon, $zoomLevel) {
    $mapSize = TILE_SIZE << $zoomLevel;
    $latitude  = min(1, max(0, .5-( log( tan( M_PI/4 + M_PI/2 * $lat/180)) / M_PI) / 2) );
    $longitude = $lon/360 + .5;
	return array("x"=>intval($longitude*$mapSize), "y"=>intval($latitude*$mapSize));
}

function polyToStr() {
    $points = func_get_args();
    if (count($points) % 2 != 0) {
        array_pop($points);
    }
    $res = array();
    for ($i = 0; $i < count($points)-1; $i+=2) {
        $res[] = $points[$i]." ".$points[$i+1];
    }
    return "POLYGON((".implode(",", $res)."))";
}

function strToPoly($str) {
    $res = explode(",", str_replace(" ", ",", preg_replace('/^[A-Z\(]+|\)+$/', "", $str)));
    for ($i = 0; $i < count($res); $i++) {
        $res[$i] *= 1;
    }
    return $res;
}

//*****************************************************************************

if (!isset($_GET["n"]) || !isset($_GET["w"]) || !isset($_GET["s"]) || !isset($_GET["e"])) {
    header("HTTP/1.0 400 Bad Request");
    echo "Bounding box missing.";
	exit;
}

if (!isset($_GET["z"])) {
    header("HTTP/1.0 400 Bad Request");
    echo "Zoom level missing.";
	exit;
}

$n = $_GET["n"];
$w = $_GET["w"];
$s = $_GET["s"];
$e = $_GET["e"];

/*
$boxPolygon = polyToStr(
    $n, $w,
	$n, $e,
	$s, $e,
	$s, $w,
    $n, $w
);
*/

$boxPolygon = polyToStr(
    $w, $n,
	$e, $n,
	$e, $s,
	$w, $s,
    $w, $n
);

$Z = $_GET["z"]*1;
$z = MAX_ZOOM-$Z;

$XY = geoToPixel($n, $w, $Z);

//*****************************************************************************

$query = "
    SELECT
        COALESCE(height, \"building:height\") AS height,
        COALESCE(levels, \"building:levels\", \"building:levels:aboveground\") AS levels,
        ST_AsText(ST_ExteriorRing(the_geom)) AS footprint
    FROM
        buildings_ffm
 	WHERE
		NOT ST_IsEmpty(ST_Intersection(ST_GeomFromText('%s', 4326), the_geom))
    ORDER BY
        height DESC, levels DESC
";

$args = array($boxPolygon);

$res = pg_query($db, vsprintf($query, array_map("pg_escape_string", $args)));

if (pg_last_error()) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

if (!pg_num_rows($res)) {
    header("HTTP/1.0 204 No Content");
    exit;
}

//*****************************************************************************

$json = array(
    "meta" => array(
        "n" => round($_GET["n"]*100000)/100000,
        "w" => round($_GET["w"]*100000)/100000,
        "s" => round($_GET["s"]*100000)/100000,
        "e" => round($_GET["e"]*100000)/100000,
        "x" => $XY["x"],
        "y" => $XY["y"],
        "z" => $Z
    ),
	"data" => array()
);

//*****************************************************************************

header("Content-Type: application/json; charset=utf-8");

while ($row = pg_fetch_object($res)) {
    $h = ($row->height ? $row->height : ($row->levels ? $row->levels*3.5 : 5))*SCALE_Z>>$z;

    if ($h <= 1) continue;

    $f = strToPoly($row->footprint);

    $fp = array();
    for ($i = 0; $i < count($f)-1; $i+=2) {
        $px = geoToPixel($f[$i+1], $f[$i], $Z);
        $fp[$i]   = $px["x"]-$XY["x"];
        $fp[$i+1] = $px["y"]-$XY["y"];
    }

    $json["data"][] = array($h, $fp);
}

echo json_encode($json);

?>