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

// How many readings are shown
$GRAPH_POINTS = 50;   // points on the graph

// Pull down menu for the table: 10, 20, 30, 40 or 50 rows, the default is 10
$rowOptions = array(10, 20, 30, 40, 50);
$TABLE_ROWS = 10;
if (isset($_GET['rows']) && in_array((int)$_GET['rows'], $rowOptions)) {
  $TABLE_ROWS = (int)$_GET['rows'];
}


// Displays the JSON file that is queried (used by the graph)
if (isset($_GET['json'])) {
  // newest readings first to pick the last ones, then put them back in time order
  $resultJson = $conn->query("SELECT ts, value
                              FROM (SELECT id, ts, value FROM potentiometer ORDER BY id DESC LIMIT $GRAPH_POINTS) AS latest
                              ORDER BY id ASC");
  $rows = array();
  while ($row = $resultJson->fetch_assoc()) {
    $rows[] = array(
      "time_received" => $row['ts'],
      "resistance" => (float)$row['value']
    );
  }
  header('Content-Type: application/json');
  echo json_encode($rows);
  exit;
}


// get the most recent readings for the table
$sql1 = "SELECT ts, value FROM potentiometer ORDER BY id DESC LIMIT $TABLE_ROWS";
$result1 = $conn->query($sql1);

// get the latest reading
$sql2 = "SELECT ts, value FROM potentiometer ORDER BY id DESC LIMIT 1";
$latest = $conn->query($sql2)->fetch_assoc();

// Total number of readings
$sql3 = "SELECT COUNT(*) AS total FROM potentiometer";
$stats = $conn->query($sql3)->fetch_assoc();
?>

<!-- HTML for the tables -->
<html>
<head>
  <title>Assignment 6 MQTT</title>
  <!-- the page reloads itself every 15 s, the same rate the ESP publishes -->
  <meta http-equiv="refresh" content="15">
  <style>
    body { text-align: center; font-family: Arial; }
    table { margin: auto; border-collapse: collapse; }
    th, td { border: 1px solid gray; padding: 5px; }
    th { background-color: lightgreen; }
    #chart-container { width: 640px; max-width: 95%; margin: auto; }
  </style>
</head>
<body>

<h1>Assignment 6 MQTT &amp; Databasing</h1>

<?php
// Displaying the latest reading
if ($latest !== null) {
  echo "<h2>Latest Potentiometer Reading: " . number_format(round($latest['value'])) . " &Omega;</h2>";
  echo "<p>Received at " . $latest['ts'] . "</p>";
} else {
  echo "<h2>No data yet.</h2>";
}
?>

<?php
// Displaying the total number of readings
echo "<p>Total readings: " . $stats['total'] . "</p>";
?>

<!-- The ChartJS section -->
<div id="chart-container">
  <canvas id="mycanvas"></canvas>
</div>

<h3>Data Received</h3>

<!-- The pull down menu on the website itself -->
<form method="get">
  Show the last
  <select name="rows" onchange="this.form.submit()">
    <?php
    foreach ($rowOptions as $option) {
      if ($option == $TABLE_ROWS) {
        echo "<option value='" . $option . "' selected>" . $option . "</option>";
      } else {
        echo "<option value='" . $option . "'>" . $option . "</option>";
      }
    }
    ?>
  </select>
  readings
</form>
<br>

<table>
  <tr>
    <th>time_received</th>
    <th>resistance (ohms)</th>
  </tr>
  <?php
  while ($row = $result1->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['ts'] . "</td>";
    echo "<td>" . number_format(round($row['value'])) . "</td>";
    echo "</tr>";
  }
  ?>
</table>

<!-- jQuery and Chart.js must come first -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.1/chart.min.js"></script>
<script>
// Pulling the info from the json
$(document).ready(function(){
  $.ajax({
    url: "?json=1",
    method: "GET",
    success: function(data) {
      var x_axis = [];
      var y_axis = [];
// graph parameters
      for (var i in data) {
        x_axis.push(data[i].time_received);
        y_axis.push(data[i].resistance);
      }
// Chart colors
      var chartdata = {
        labels: x_axis,
        datasets: [
          {
            label: 'Resistance',
            backgroundColor: 'rgba(0, 126, 8, 1)',
            borderColor: 'rgba(0, 126, 8, 1)',
            hoverBackgroundColor: 'rgba(200, 200, 200, 1)',
            hoverBorderColor: 'rgba(200, 200, 200, 1)',
            pointRadius: 3,
            tension: 0.2,
            fill: false,
            data: y_axis
          }
        ]
      };

      var ctx = $("#mycanvas");
// The graph section
      var lineGraph = new Chart(ctx, {
        type: 'line',
        data: chartdata,
        options: {
          plugins: {
            title: { display: true, text: 'Potentiometer (last <?php echo $GRAPH_POINTS; ?> readings)' }
          },
          scales: {
            x: { title: { display: true, text: 'Time' } },
            y: { beginAtZero: true, suggestedMax: 200000, title: { display: true, text: 'Resistance (ohms)' } }
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
