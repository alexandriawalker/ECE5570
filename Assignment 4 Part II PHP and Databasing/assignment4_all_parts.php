<?php
// connection to the database
$servername = "localhost";
$username = "u223090689_db_AlexWalker";
$password = "ECE570iot";  
$dbname = "u223090689_AlexWalker";
// If connection fails
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Displays the JSON file that is queried
if (isset($_GET['json'])) {
  $jsonNode = $conn->real_escape_string($_GET['json']);
  $resultJson = $conn->query("SELECT node_name, time_received, temperature, humidity FROM sensor_data WHERE node_name = '$jsonNode' ORDER BY time_received");
  $rows = array();
  while ($row = $resultJson->fetch_assoc()) {
    $rows[] = $row;
  }
  header('Content-Type: application/json');
  echo json_encode($rows);
  exit;
}


// Base64 decoding section
$params = $_GET;
$rawQuery = $_SERVER['QUERY_STRING'];
if ($rawQuery != "" && !isset($_GET['nodeId']) && !isset($_GET['nodeTemp']) && !isset($_GET['nodeHumid']) && !isset($_GET['view'])) {
  $decoded = base64_decode(rawurldecode($rawQuery));
  parse_str($decoded, $params);
}

// Parameters accepted in the URL
$TEMP_MIN = -10;  $TEMP_MAX = 100;
$HUMID_MIN = 0;  $HUMID_MAX = 100;

$message = "";

// only run if the URL contains a node or a temperature
if (isset($params['nodeId']) || isset($params['nodeTemp'])) {

  // if a key is missing from the URL, use an empty value, and the checks below reject it
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

  // humidity is required
  if (isset($params['nodeHumid'])) {
    $varHumid = $params['nodeHumid'];
  } else {
    $varHumid = "";
  }

  // use the time from the URL, or use the database for the current time
  if (isset($params['timeReceived'])) {
    $varTime = $conn->real_escape_string($params['timeReceived']);
  } else {
    $row = $conn->query("SELECT NOW()")->fetch_row();
    $varTime = $row[0];
  }

  // Making sure the node is registered
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
    // everything is valid so far - check where time comes from
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


// get the registered nodes, sorted by name
$sql1 = "SELECT * FROM sensor_register ORDER BY node_name";
$result1 = $conn->query($sql1);

// get the sensor data, first sorted by node name, then by time
$sql2 = "SELECT * FROM sensor_data ORDER BY node_name, time_received";
$result2 = $conn->query($sql2);

// Pull Down Menu for averages, max, min, and graph
$selected = "";
if (isset($_GET['view'])) {
  $selected = $conn->real_escape_string($_GET['view']);
} elseif (isset($params['nodeId'])) {
  $selected = $conn->real_escape_string($params['nodeId']);
}
// defaults to the node in which in the URL
$check = $conn->query("SELECT * FROM sensor_register WHERE node_name = '$selected'");
if ($check->num_rows == 0) {
  $first = $conn->query("SELECT node_name FROM sensor_register ORDER BY node_name LIMIT 1")->fetch_row();
  $selected = $first[0];
}

// list of nodes for the pull down menu
$result4 = $conn->query("SELECT node_name FROM sensor_register ORDER BY node_name");

// Average, Min, Max 
$sql3 = "SELECT AVG(temperature) AS avg_temp, AVG(humidity) AS avg_humid,
                MAX(temperature) AS max_temp, MIN(humidity) AS min_humid
         FROM sensor_data
         WHERE node_name = '$selected'";
$stats = $conn->query($sql3)->fetch_assoc();
?>

<!-- HTML for the tables -->
<html>
<head>
  <title>Assignment 4 Part 2</title>
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

<!-- The pull down menu on the website itself -->
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
// Displaying the average, min and max of the node that is selected
if ($stats['avg_temp'] !== null) {
  echo "<p>The Average Temperature for " . $selected . " has been: " . round($stats['avg_temp'], 2) . " F<br>";
  echo "The Average Humidity for " . $selected . " has been: " . round($stats['avg_humid'], 2) . " %<br>";
  echo "The Max Temperature for " . $selected . " has been: " . $stats['max_temp'] . " F<br>";
  echo "The Minimum Humidity for " . $selected . " has been: " . $stats['min_humid'] . " %</p>";
} else {
  echo "<p>No data yet for " . $selected . ".</p>";
}
?>

<!-- The ChartJS section -->
<div id="chart-container">
  <canvas id="mycanvas"></canvas>
</div>

<!-- jQuery and Chart.js must come first -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.1/chart.min.js"></script>
<script>
// the node chosen in the pull down menu gets put here
var node = "<?php echo $selected; ?>";

// Pulling the info from the json
$(document).ready(function(){
  $.ajax({
    url: "?json=" + node,
    method: "GET",
    success: function(data) {
      var x_axis = [];
      var y_axis = [];
// graph parameters
      for (var i in data) {
        x_axis.push(data[i].time_received);
        y_axis.push(data[i].temperature);
      }
// Chart colors
      var chartdata = {
        labels: x_axis,
        datasets: [
          {
            label: 'Temperature',
            backgroundColor: 'rgba(0, 126, 8, 1)',
            borderColor: 'rgba(200, 200, 200, 0.75)',
            hoverBackgroundColor: 'rgba(200, 200, 200, 1)',
            hoverBorderColor: 'rgba(200, 200, 200, 1)',
            data: y_axis
          }
        ]
      };

      var ctx = $("#mycanvas");
// The graph section
      var barGraph = new Chart(ctx, {
        type: 'bar',
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
