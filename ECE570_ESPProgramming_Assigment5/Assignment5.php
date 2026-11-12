<?php
// connection to the database
$servername = "localhost";
$username = "u223090689_db_AlexWalker";
$password = "PASSWORD";
$dbname = "u223090689_AlexWalker";
// If connection fails
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  http_response_code(500);
  die("Connection failed: " . $conn->connect_error);
}

// make sure the counter table exists, one row per node (id 1 = node_1, id 2 = node_2)
$conn->query("CREATE TABLE IF NOT EXISTS sensoractivity (
  id INT PRIMARY KEY,
  message_count INT NOT NULL DEFAULT 0
)");

// make the database reject duplicate timestamps from the same node (only added once)
$hasKey = $conn->query("SHOW INDEX FROM data_sensor WHERE Key_name = 'uniq_node_time'");
if ($hasKey && $hasKey->num_rows == 0) {
  $conn->query("ALTER TABLE data_sensor ADD UNIQUE KEY uniq_node_time (node_name, time_received)");
}

// Base64 decoding section
$params = $_GET;
$rawQuery = $_SERVER['QUERY_STRING'];
if ($rawQuery != "" && !isset($_GET['nodeId']) && !isset($_GET['nodeTemp']) && !isset($_GET['nodeDistance']) && !isset($_GET['timeReceived']) && !isset($_GET['view'])) {
  $decoded = base64_decode(rawurldecode($rawQuery));
  parse_str($decoded, $params);
}

// Parameters accepted in the URL (every message has both values)
$limits = array(
  'nodeTemp'     => array('min' => -40, 'max' => 85,  'name' => 'temperature (C)'),
  'nodeDistance' => array('min' => 0,   'max' => 400, 'name' => 'distance (cm)')
);

$message = "";
$status = 200;   // code sent back to the ESP: 200 stored, 409 duplicate, 403 not registered, 400 invalid

// only run if the URL contains a node, a time or a data value
if (isset($params['nodeId']) || isset($params['timeReceived']) || isset($params['nodeTemp']) || isset($params['nodeDistance'])) {

  // if a key is missing from the URL, use an empty value, and the checks below reject it
  $varId = isset($params['nodeId']) ? $conn->real_escape_string($params['nodeId']) : "";
  $varTemp = isset($params['nodeTemp']) ? $params['nodeTemp'] : "";
  $varDistance = isset($params['nodeDistance']) ? $params['nodeDistance'] : "";

  // use the time from the URL (sent by the ESP), if it is missing the data is rejected below
  $varTime = "";
  if (isset($params['timeReceived'])) {
    $timeRaw = trim($params['timeReceived']);
    $timeRaw = str_replace('T', ' ', $timeRaw);       // change the T into a space
    $timeRaw = preg_replace('/\.\d+/', '', $timeRaw);  // remove the fractional seconds
    $timeRaw = rtrim($timeRaw, 'Z');                  // remove the Z at the end
    $varTime = $conn->real_escape_string($timeRaw);
  }

  // Making sure the node is registered
  $registered = $conn->query("SELECT * FROM registered_sensor WHERE node_name = '$varId'");

  // check everything; only insert if every check passes
  if (!$registered || $registered->num_rows == 0) {
    $message = "Error: this node is not registered. Data not accepted.";
    $status = 403;
  } elseif ($varTime == "") {
    $message = "Error: timestamp is missing. Data not accepted.";
    $status = 400;
  } elseif (strtotime($varTime) === false || date('Y-m-d H:i:s', strtotime($varTime)) != $varTime) {
    $message = "Error: time must be in the format YYYY-MM-DD HH:MM:SS. Data not accepted.";
    $status = 400;
  } elseif (!is_numeric($varTemp) || $varTemp < $limits['nodeTemp']['min'] || $varTemp > $limits['nodeTemp']['max']) {
    $message = "Error: " . $limits['nodeTemp']['name'] . " is missing or out of range. Data not accepted.";
    $status = 400;
  } elseif (!is_numeric($varDistance) || $varDistance < $limits['nodeDistance']['min'] || $varDistance > $limits['nodeDistance']['max']) {
    $message = "Error: " . $limits['nodeDistance']['name'] . " is missing or out of range. Data not accepted.";
    $status = 400;
  } else {
    // everything is valid so far - check if this node already has data for that time
    $duplicate = $conn->query("SELECT * FROM data_sensor WHERE node_name = '$varId' AND time_received = '$varTime'");
    if ($duplicate && $duplicate->num_rows > 0) {
      $message = "Error: this node already has data for that exact time. Data not accepted.";
      $status = 409;
    } else {
      $varTemp = (float)$varTemp;
      $varDistance = (float)$varDistance;
      $sql = "INSERT INTO data_sensor (node_name, time_received, temperature, proximity)
              VALUES ('$varId', '$varTime', '$varTemp', '$varDistance')";
      if ($conn->query($sql) === TRUE) {
        // the record was stored, so add 1 to this node's counter
        $nodeNumber = (int)str_replace('node_', '', $varId);
        $conn->query("INSERT INTO sensoractivity (id, message_count) VALUES ($nodeNumber, 1)
                      ON DUPLICATE KEY UPDATE message_count = message_count + 1");
        $message = "New record created successfully";
        $status = 200;
      } elseif ($conn->errno == 1062) {
        // the unique key caught a duplicate that got past the check above
        $message = "Error: this node already has data for that exact time. Data not accepted.";
        $status = 409;
      } else {
        $message = "Error: " . $conn->error;
        $status = 500;
      }
    }
  }
}

// send the status code back, the ESP reads it to know what happened
if ($message !== "") {
  http_response_code($status);
  // the ESP only needs the result, not the whole page
  if (strpos(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "", "ESP8266") !== false) {
    echo "<p><b>" . $message . "</b></p>";
    exit;
  }
}

// get the registered nodes, sorted by name
$nodes = array();
$result1 = $conn->query("SELECT * FROM registered_sensor ORDER BY node_name");
if ($result1) {
  while ($row = $result1->fetch_assoc()) {
    $nodes[] = $row;
  }
}
$nodeNames = array_column($nodes, 'node_name');

// Names shown next to each node
$nodeLabels = array('node_1' => 'Pushbutton', 'node_2' => 'Tilt Sensor');

// Pull Down Menu for the table, averages, max, min, and graph
$view = "";
if (isset($_GET['view'])) {
  $view = $_GET['view'];
} elseif (isset($params['nodeId'])) {
  $view = $params['nodeId'];
}
// defaults to the first registered node so a graph shows right away
if ($view != "all" && !in_array($view, $nodeNames)) {
  $view = count($nodeNames) > 0 ? $nodeNames[0] : "all";
}

// get the message counter for each node
$counts = array();
$resultActivity = $conn->query("SELECT id, message_count FROM sensoractivity");
if ($resultActivity) {
  while ($row = $resultActivity->fetch_assoc()) {
    $counts[$row['id']] = $row['message_count'];
  }
}

// get the sensor data for the selected view, sorted by time
if ($view == "all") {
  $resultData = $conn->query("SELECT node_name, time_received, temperature, proximity FROM data_sensor ORDER BY node_name, time_received");
} else {
  $resultData = $conn->query("SELECT node_name, time_received, temperature, proximity FROM data_sensor WHERE node_name = '" . $conn->real_escape_string($view) . "' ORDER BY time_received");
}

// Heading for the data table
if ($view == "all") {
  $heading = "All Data";
} else {
  $num = str_replace('node_', '', $view);
  $heading = "Node " . $num . (isset($nodeLabels[$view]) ? " - " . $nodeLabels[$view] : "");
}
?>

<!-- HTML for the tables -->
<html>
<head>
  <title>Assignment 5</title>
  <!-- Refreshes every 30 seconds so new data shows up -->
  <meta http-equiv="refresh" content="30;url=?view=<?php echo urlencode($view); ?>">
  <style>
    body { text-align: center; font-family: Arial; }
    table { margin: auto; border-collapse: collapse; }
    th, td { border: 1px solid gray; padding: 5px; }
    th { background-color: lightgreen; }
    #chart-container { width: 640px; max-width: 95%; margin: auto; }
  </style>
</head>
<body>

<h1>Assignment 5 PHP & Databasing</h1>

<!-- This will list if the data was successfully put in the table, or if it was rejected -->
<?php if ($message !== "") { echo "<p><b>" . $message . "</b></p>"; } ?>

<h3>Registered Sensor Nodes</h3>
<table>
  <tr>
    <th>node_name</th>
    <th>manufacturer</th>
    <th>longitude</th>
    <th>latitude</th>
  </tr>
  <?php
  foreach ($nodes as $row) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['node_name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['manufacturer']) . "</td>";
    echo "<td>" . $row['longitude'] . "</td>";
    echo "<td>" . $row['latitude'] . "</td>";
    echo "</tr>";
  }
  ?>
</table>

<h3>Sensor Activity</h3>
<table>
  <tr>
    <th>Node</th>
    <th>Logged Data</th>
  </tr>
  <?php
  // one row for every registered node, 0 if it has no data yet
  foreach ($nodes as $row) {
    $id = (int)str_replace('node_', '', $row['node_name']);
    $count = isset($counts[$id]) ? $counts[$id] : 0;
    echo "<tr><td>" . htmlspecialchars($row['node_name']) . "</td><td>" . $count . "</td></tr>";
  }
  ?>
</table>

<!-- The pull down menu on the website itself -->
<h3>Select Data to View</h3>
<form method="get">
  <select name="view" onchange="this.form.submit()">
    <?php
    foreach ($nodes as $row) {
      $name = $row['node_name'];
      $sel = ($view == $name) ? "selected" : "";
      echo "<option value=\"" . htmlspecialchars($name) . "\" $sel>Node " . htmlspecialchars(str_replace('node_', '', $name)) . "</option>";
    }
    ?>
    <option value="all" <?php if ($view == "all") echo "selected"; ?>>All Data</option>
  </select>
</form>

<h3><?php echo htmlspecialchars($heading); ?></h3>
<table>
  <tr>
    <th>Node Name</th>
    <th>Time Stamp</th>
    <th>Temp (C)</th>
    <th>distance (cm)</th>
  </tr>
  <?php
  if ($resultData) {
    while ($row = $resultData->fetch_assoc()) {
      echo "<tr>";
      echo "<td>" . htmlspecialchars($row['node_name']) . "</td>";
      echo "<td>" . $row['time_received'] . "</td>";
      echo "<td>" . $row['temperature'] . "</td>";
      echo "<td>" . $row['proximity'] . "</td>";
      echo "</tr>";
    }
  }
  ?>
</table>

<?php
// Displaying the average, min and max of the node that is selected (not for All Data)
if ($view != "all") {

  $viewSql = $conn->real_escape_string($view);
  // Average, Min, Max
  $sqlStats = "SELECT AVG(temperature) AS avg_temp, MIN(temperature) AS min_temp, MAX(temperature) AS max_temp,
                      AVG(proximity) AS avg_dist, MIN(proximity) AS min_dist, MAX(proximity) AS max_dist
               FROM data_sensor
               WHERE node_name = '$viewSql'";
  $resultStats = $conn->query($sqlStats);
  $stats = null;
  if ($resultStats) {
    $stats = $resultStats->fetch_assoc();
  }

  if ($stats && $stats['avg_temp'] !== null) {
    echo "<p>The Average Temperature for " . htmlspecialchars($view) . " has been: " . round($stats['avg_temp'], 2) . " C<br>";
    echo "The Minimum Temperature for " . htmlspecialchars($view) . " has been: " . $stats['min_temp'] . " C<br>";
    echo "The Maximum Temperature for " . htmlspecialchars($view) . " has been: " . $stats['max_temp'] . " C</p>";
    echo "<p>The Average Distance for " . htmlspecialchars($view) . " has been: " . round($stats['avg_dist'], 2) . " cm<br>";
    echo "The Minimum Distance for " . htmlspecialchars($view) . " has been: " . $stats['min_dist'] . " cm<br>";
    echo "The Maximum Distance for " . htmlspecialchars($view) . " has been: " . $stats['max_dist'] . " cm</p>";
  } else {
    echo "<p>No data yet for " . htmlspecialchars($view) . ".</p>";
  }

  // data for the graph, sorted by time
  $chartLabels = array();
  $chartTemps = array();
  $chartDistances = array();
  $resultChart = $conn->query("SELECT time_received, temperature, proximity FROM data_sensor WHERE node_name = '$viewSql' ORDER BY time_received");
  if ($resultChart) {
    while ($row = $resultChart->fetch_assoc()) {
      $chartLabels[] = $row['time_received'];
      $chartTemps[] = (float)$row['temperature'];
      $chartDistances[] = (float)$row['proximity'];
    }
  }
?>

<!-- The ChartJS section -->
<div id="chart-container">
  <canvas id="mycanvas"></canvas>
</div>

<!-- Chart.js must come first -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.1/chart.min.js"></script>
<script>
// the data for the node chosen in the pull down menu gets put here
var x_axis = <?php echo json_encode($chartLabels); ?>;
var y_temp = <?php echo json_encode($chartTemps); ?>;
var y_distance = <?php echo json_encode($chartDistances); ?>;
var nodeNumber = <?php echo json_encode(str_replace('node_', '', $view)); ?>;

// Chart colors
var chartdata = {
  labels: x_axis,
  datasets: [
    {
      label: 'Temperature (C)',
      borderColor: 'rgba(0, 126, 8, 1)',          // green line
      backgroundColor: 'rgba(0, 126, 8, 1)',
      pointBackgroundColor: 'rgba(0, 126, 8, 1)',
      fill: false,
      tension: 0,
      data: y_temp,
      yAxisID: 'yTemp'      // left side of the graph
    },
    {
      label: 'Distance (cm)',
      borderColor: 'rgba(0, 90, 220, 1)',         // blue line
      backgroundColor: 'rgba(0, 90, 220, 1)',
      pointBackgroundColor: 'rgba(0, 90, 220, 1)',
      fill: false,
      tension: 0,
      data: y_distance,
      yAxisID: 'yDistance'  // right side of the graph
    }
  ]
};

// The graph section
var lineGraph = new Chart(document.getElementById('mycanvas'), {
  type: 'line',
  data: chartdata,
  options: {
    plugins: {
      title: { display: true, text: 'Sensor Node ' + nodeNumber }
    },
    scales: {
      x: { title: { display: true, text: 'Time' } },
      yTemp: { position: 'left', title: { display: true, text: 'Temperature (C)' } },
      yDistance: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Distance (cm)' } }
    }
  }
});
</script>

<?php } ?>

</body>
</html>
