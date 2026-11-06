<?php
// connect to the database
$servername = "localhost";
$username = "u223090689_db_AlexWalker";
$password = "PASSWORD";   // <-- put your real password here
$dbname = "u223090689_AlexWalker";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// ---------- ADDED (3.3 Q3 and the graph): send one node's data as JSON ----------
// Example: assignment4.php?json=node_1
// The graph asks for this JSON. Only the JSON is printed, then the page stops.
if (isset($_GET['json'])) {
  $jsonNode = $conn->real_escape_string($_GET['json']);
  $resultJson = $conn->query("SELECT node_name, time_received, temperature, humidity FROM sensor_data WHERE node_name = '$jsonNode' ORDER BY time_received");

  // put every row into one list
  $rows = array();
  while ($row = $resultJson->fetch_assoc()) {
    $rows[] = $row;
  }

  header('Content-Type: application/json');
  echo json_encode($rows);
  exit;
}
// ---------- END ADDED ----------

// ---------- ADDED (3.3 Q4): BASE64 message ----------
// Example: assignment4.php?bm9kZUlkPW5vZGVfMSZub2RlVGVtcD0zNCZub2RlSHVtaWQ9NTU=
// If the URL has no normal keys, decode it. After decoding we have the same text
// as a normal URL (nodeId=node_1&nodeTemp=34&...) and parse_str splits it into $params.
$params = $_GET;
$rawQuery = $_SERVER['QUERY_STRING'];
if ($rawQuery != "" && !isset($_GET['nodeId']) && !isset($_GET['nodeTemp']) && !isset($_GET['nodeHumid']) && !isset($_GET['view'])) {
  $decoded = base64_decode(rawurldecode($rawQuery));
  parse_str($decoded, $params);
}
// ---------- END ADDED ----------

// ---------- insert data sent in the URL (GET) ----------
// Example: assignment4.php?nodeId=node_1&nodeTemp=34&nodeHumid=55
// If timeReceived is not in the URL, the database stamps the time.

// accepted ranges (set these to your Section 1.2 constraints)
$TEMP_MIN = -10;  $TEMP_MAX = 100;
$HUMID_MIN = 0;  $HUMID_MAX = 100;

$message = "";

// only run if the URL contains a node or a temperature
if (isset($params['nodeId']) || isset($params['nodeTemp'])) {

  // create a variable for each key (escape_string makes the text safe to use in SQL)
  // if a key is missing from the URL we use an empty value, and the checks below reject it
  if (isset($params['nodeId'])) {
    $varId = $conn->real_escape_string($params['nodeId']);
  } else {
    $varId = "";
  }

  // temperature is required
  if (isset($params['nodeTemp'])) {
    $varTemp = $params['nodeTemp'];
  } else {
    $varTemp = "";
  }

  // humidity is required (the table does not allow it to be empty)
  if (isset($params['nodeHumid'])) {
    $varHumid = $params['nodeHumid'];
  } else {
    $varHumid = "";
  }

  // use the time from the URL, or ask the database for the current time
  if (isset($params['timeReceived'])) {
    $varTime = $conn->real_escape_string($params['timeReceived']);
  } else {
    $row = $conn->query("SELECT NOW()")->fetch_row();
    $varTime = $row[0];
  }

  // is the node registered?
  $registered = $conn->query("SELECT * FROM sensor_register WHERE node_name = '$varId'");

  // check everything; only insert if every check passes
  if (!is_numeric($varTemp) || $varTemp < $TEMP_MIN || $varTemp > $TEMP_MAX) {
    $message = "Error: temperature is missing or out of range. Data not accepted.";
  } elseif (!is_numeric($varHumid) || $varHumid < $HUMID_MIN || $varHumid > $HUMID_MAX) {
    $message = "Error: humidity is missing or out of range. Data not accepted.";
  } elseif (strtotime($varTime) === false || date('Y-m-d H:i:s', strtotime($varTime)) != $varTime) {
    $message = "Error: time must be in the format YYYY-MM-DD HH:MM:SS. Data not accepted.";
  } elseif ($registered->num_rows == 0) {
    $message = "Error: this node is not registered. Data not accepted.";
  } else {
    // everything is valid so far - has this node already sent data at this exact time?
    $duplicate = $conn->query("SELECT * FROM sensor_data WHERE node_name = '$varId' AND time_received = '$varTime'");
    if ($duplicate->num_rows > 0) {
      $message = "Error: this node already has data for that exact time. Data not accepted.";
    } else {
      $sql = "INSERT INTO sensor_data (node_name, time_received, temperature, humidity)
              VALUES ('$varId', '$varTime', '$varTemp', '$varHumid')";
      if ($conn->query($sql) === TRUE) {
        $message = "New record created successfully";
      } else {
        $message = "Error: " . $conn->error;
      }
    }
  }
}
// ---------- END insert ----------

// get the registered nodes (sorted by name)
$sql1 = "SELECT * FROM sensor_register ORDER BY node_name";
$result1 = $conn->query($sql1);

// get the sensor data (sorted by node name, then time)
$sql2 = "SELECT * FROM sensor_data ORDER BY node_name, time_received";
$result2 = $conn->query($sql2);

// ---------- ADDED (3.2): which node is chosen in the pull down menu? ----------
// The menu sends ?view=node_2. If nothing was chosen, use the node that was just
// inserted. If that is not there either, use the first registered node.
$selected = "";
if (isset($_GET['view'])) {
  $selected = $conn->real_escape_string($_GET['view']);
} elseif (isset($params['nodeId'])) {
  $selected = $conn->real_escape_string($params['nodeId']);
}

// is the chosen node registered? if not, use the first registered node
$check = $conn->query("SELECT * FROM sensor_register WHERE node_name = '$selected'");
if ($check->num_rows == 0) {
  $first = $conn->query("SELECT node_name FROM sensor_register ORDER BY node_name LIMIT 1")->fetch_row();
  $selected = $first[0];
}

// list of nodes for the pull down menu
$result4 = $conn->query("SELECT node_name FROM sensor_register ORDER BY node_name");

// ADDED (3.1): average temperature, average humidity, max temperature and
// minimum humidity for the chosen node (no data gives empty values)
$sql3 = "SELECT AVG(temperature) AS avg_temp, AVG(humidity) AS avg_humid,
                MAX(temperature) AS max_temp, MIN(humidity) AS min_humid
         FROM sensor_data
         WHERE node_name = '$selected'";
$stats = $conn->query($sql3)->fetch_assoc();
// ---------- END ADDED ----------
?>

<html>
<head>
  <title>SSU IoT Lab</title>
  <style>
    body { text-align: center; font-family: Arial; }
    table { margin: auto; border-collapse: collapse; }
    th, td { border: 1px solid gray; padding: 5px; }
    th { background-color: lightgreen; }
    #chart-container { width: 640px; max-width: 95%; margin: auto; }
  </style>
</head>
<body>

<h1>Assignment 4 PHP & Databasing</h1>

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
  while ($row = $result1->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['node_name'] . "</td>";
    echo "<td>" . $row['manufacturer'] . "</td>";
    echo "<td>" . $row['longitude'] . "</td>";
    echo "<td>" . $row['latitude'] . "</td>";
    echo "</tr>";
  }
  ?>
</table>

<h3>Data Received</h3>
<table>
  <tr>
    <th>node_name</th>
    <th>time_received</th>
    <th>temperature</th>
    <th>humidity</th>
  </tr>
  <?php
  while ($row = $result2->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['node_name'] . "</td>";
    echo "<td>" . $row['time_received'] . "</td>";
    echo "<td>" . $row['temperature'] . "</td>";
    echo "<td>" . $row['humidity'] . "</td>";
    echo "</tr>";
  }
  ?>
</table>

<!-- ADDED (3.2): pull down menu. Picking a node reloads the page with ?view=node_name -->
<h3>Select a Node</h3>
<form method="get">
  <select name="view" onchange="this.form.submit()">
    <?php
    while ($row = $result4->fetch_assoc()) {
      if ($row['node_name'] == $selected) {
        echo "<option value='" . $row['node_name'] . "' selected>" . $row['node_name'] . "</option>";
      } else {
        echo "<option value='" . $row['node_name'] . "'>" . $row['node_name'] . "</option>";
      }
    }
    ?>
  </select>
</form>

<?php
// ADDED (3.1): statistics for the chosen node
if ($stats['avg_temp'] !== null) {
  echo "<p>The Average Temperature for " . $selected . " has been: " . round($stats['avg_temp'], 2) . " F<br>";
  echo "The Average Humidity for " . $selected . " has been: " . round($stats['avg_humid'], 2) . " %<br>";
  echo "The Max Temperature for " . $selected . " has been: " . $stats['max_temp'] . " F<br>";
  echo "The Minimum Humidity for " . $selected . " has been: " . $stats['min_humid'] . " %</p>";
} else {
  echo "<p>No data yet for " . $selected . ".</p>";
}
?>

<!-- ADDED (3.1): the graph -->
<div id="chart-container">
  <canvas id="mycanvas"></canvas>
</div>

<!-- jQuery and Chart.js must come before our own code -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.1/chart.min.js"></script>
<script>
// the node chosen in the pull down menu (PHP fills this in)
var node = "<?php echo $selected; ?>";

$(document).ready(function(){
  $.ajax({
    url: "?json=" + node,   // this same page, asking for JSON (see the top of the file)
    method: "GET",
    success: function(data) {
      var x_axis = [];
      var y_axis = [];

      for (var i in data) {
        x_axis.push(data[i].time_received);   // must match the database columns
        y_axis.push(data[i].temperature);
      }

      var chartdata = {
        labels: x_axis,
        datasets: [
          {
            label: 'Temperature',
            // 3.3 Q2: change these four rgba(...) values to a green such as rgba(0, 160, 0, 0.75)
            backgroundColor: 'rgba(200, 200, 200, 0.75)',
            borderColor: 'rgba(200, 200, 200, 0.75)',
            hoverBackgroundColor: 'rgba(200, 200, 200, 1)',
            hoverBorderColor: 'rgba(200, 200, 200, 1)',
            data: y_axis
          }
        ]
      };

      var ctx = $("#mycanvas");

      var barGraph = new Chart(ctx, {
        type: 'bar',   // 3.3 Q1: change 'bar' to 'line'
        data: chartdata,
        options: {
          plugins: {
            title: { display: true, text: 'Sensor Node ' + node.replace("node_", "") }
          },
          scales: {
            x: { title: { display: true, text: 'Time' } },
            y: { title: { display: true, text: 'Temperature (F)' } }
          }
        }
      });
    },
    error: function(data) {
      console.log(data);
    }
  });
});
</script>

</body>
</html>
