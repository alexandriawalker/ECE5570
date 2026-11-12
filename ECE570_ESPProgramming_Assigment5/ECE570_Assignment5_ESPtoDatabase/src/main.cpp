// ;--------------------------------------------
// ; Title: ESP Programming and Connecting to the database
// ;--------------------------------------------
// ; Program Detail:
// ;--------------------------------------------
// ; Purpose: Design and implement an ESP-based IoT system that collects sensor data, 
// ;          transmits it reliably to a database, and displays real-time measurements 
// ;          through an interactive webpage.
// ;
// ; Inputs: ADC (A0) - Temp Input, D6 - Trigger Sonic Sensor, D3 - Button, D2 - Tilt Switch
// ; Outputs: D7 - Echo Sonic Sensor, USB terminal, Tx data to database
// ; Date: 11/12/2026
// ; Compiler: VS Code with PlatformIO
// ; Author: Alexandria Walker
// ; Versions:
// ;           V1 - Initial File to ensure button/tilt switch triggers correctly
// ;           V2 - Added Temp Sensor and Ultra Sonic Sensor to ensure those trigger correctly
// ;           V3 - Added WiFi settings and connection
// ;           V4 - Added database connection
// ;           V5 - Added reception of php codes for successful recording/failed recording
// ;           V6 - Added time.h to keep sync between time.io and the ESP
// ;

// ; -------------------------------------------
// ; File Dependancies
// ; -------------------------------------------
#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecure.h>
#include <time.h>

// ; -------------------------------------------
// ; WiFi Connection
// ; -------------------------------------------
const char* ssid = "Alex's pocket computer";
const char* password = "ECE570iot";

// ; -------------------------------------------
// ; PHP URL Connection
// ; -------------------------------------------
String serverURL = "https://ece570iotaw.com/Assignment5.php";
// -------------------------------------------------

// ; -------------------------------------------
// ; Definitions
// ; -------------------------------------------
// ; ESP Pins
#define BUTTON_PIN D3
#define TILT_PIN   D2
#define TRIG_PIN   D6
#define ECHO_PIN   D7
// ; Button/Tilt Switch default setting
#define BUTTON_PRESSED LOW
#define TILT_ACTIVE    HIGH
// ; Timing 
#define LOCKOUT_MS         400UL     // ignore a switch debounce time
#define RETRY_INTERVAL_MS  30000UL   // retry a failed message every 30 s
#define RESYNC_MS          600000UL  // re-read the real time from timeapi.io every 10 min
#define ZONE_PROMPT_MS     10000UL   // wait this long for a time zone choice at startup
// ; Data Queue
#define QUEUE_SIZE         10        // how many unsent messages we can remember

// ; -------------------------------------------
// ; Time Zones
// ; -------------------------------------------
const char* zoneIds[7] = {
  "America/New_York", "America/Chicago", "America/Denver", "America/Los_Angeles",
  "America/Anchorage", "Pacific/Honolulu", "America/Puerto_Rico"
};
const char* zoneShort[7] = {"ET", "CT", "MT", "PT", "AKT", "HAT", "AT"};

String timezone = "America/Los_Angeles";   // default is Pacific
String timezoneShort = "PT";
bool waitingForZone = false;               // true after the user types z

// ; -------------------------------------------
// ; Switch States
// ; -------------------------------------------
int lastButtonState;
int lastTiltState;
unsigned long lastButtonTime = 0;
unsigned long lastTiltTime = 0;
bool buttonEvent = false;   // set by check_switch(), used by handle_activation()
bool tiltEvent = false;     // set by check_tilt(),   used by handle_activation()

// ; -------------------------------------------
// ; ESP Closck sync to time.io
// ; -------------------------------------------
bool timeSynced = false;
time_t syncEpoch = 0;                 // date/time at the moment of the sync
unsigned long syncMillis = 0;         // millis() at the moment of the sync
unsigned long lastSyncAttempt = 0;

// ; -------------------------------------------
// ; Qurry Message set up
// ; -------------------------------------------
struct Message {
  int node;        // 1 or 2
  String ts;       // original timestamp (kept for retries)
  float s1;        // temperature (C)
  float s2;        // distance (cm)
};

Message queueBuf[QUEUE_SIZE];
int queueCount = 0;
unsigned long lastRetryTime = 0;

enum SendResult { SEND_OK, SEND_REJECTED, SEND_RETRY };

// ; -------------------------------------------
// ; Function Declarations
// ; -------------------------------------------
void initialize_serial();
void initialize_sensors();
void initialize_wifi();
void select_timezone_at_startup();
void print_timezone_menu();
bool apply_timezone_choice(String input);
void check_serial();
void check_switch();
void check_tilt();
void handle_activation();
void process_node(int node);
bool read_sensor_1(float &value);
bool read_sensor_2(float &value);
bool sync_time();
void check_time_sync();
String read_time();
bool enqueue_message(int node, String ts, float s1, float s2);
void dequeue_message();
SendResult send_message(const Message &m);
void transmit();
void check_error();
void print_help();

// ; -------------------------------------------
// ; Set Up
// ; -------------------------------------------
void setup() {
  initialize_serial();          // first, so the other functions can print messages
  initialize_sensors();
  select_timezone_at_startup(); 
  initialize_wifi();
  sync_time();                  // get the real date/time once
  print_help();
}

// ; -------------------------------------------
// ; Loop Function that only calls other functions
// ; -------------------------------------------
void loop() {
  check_serial();        // commands from the USB serial monitor
  check_switch();        // pushbutton pressed?
  check_tilt();          // tilt sensor activated?
  handle_activation();   // read sensors + time, queue and send
  check_error();         // retry unsent messages every 30 s
  check_time_sync();     // refresh the clock now and then
}

// ; -------------------------------------------
// ; Set Up Serial
// ; -------------------------------------------
void initialize_serial() {
  Serial.begin(9600);
  delay(500);
  Serial.println("Starting...");
}


// ; -------------------------------------------
// ; Sensor Function
// ; -------------------------------------------
void initialize_sensors() {
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  pinMode(TILT_PIN, INPUT_PULLUP);   // pull-up so the pin does not float
  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);          // HC-SR04 echo is 5 V: use a voltage divider!

  // Save the starting state of the switches
  lastButtonState = digitalRead(BUTTON_PIN);
  lastTiltState = digitalRead(TILT_PIN);
}

// ; -------------------------------------------
// ; WiFi Function
// ; -------------------------------------------
void initialize_wifi() {
  WiFi.begin(ssid, password);
  Serial.print("Connecting to Wi-Fi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();
  Serial.print("Connected! IP address: ");
  Serial.println(WiFi.localIP());
  Serial.print("RSSI: ");
  Serial.print(WiFi.RSSI());
  Serial.println(" dBm");
}

// ; -------------------------------------------
// ; Time Zone Function
// ; -------------------------------------------
void print_timezone_menu() {
  Serial.println("1) Eastern Time Zone (ET) - New York City, NY");
  Serial.println("2) Central Time Zone (CT) - Chicago, IL");
  Serial.println("3) Mountain Time Zone (MT) - Denver, CO");
  Serial.println("4) Pacific Time Zone (PT) - Los Angeles, CA");
  Serial.println("5) Alaska Time Zone (AKT) - Anchorage, AK");
  Serial.println("6) Hawaii-Aleutian Time Zone (HAT) - Honolulu, HI");
  Serial.println("7) Atlantic Time Zone (AT) - San Juan, Puerto Rico");
  Serial.println("-> Select Your Time Zone (The default time is PT):");
}

void select_timezone_at_startup() {
  print_timezone_menu();
  unsigned long start = millis();
  while (millis() - start < ZONE_PROMPT_MS) {
    if (Serial.available() > 0) {
      String input = Serial.readStringUntil('\n');
      input.trim();
      if (input != "") {
        apply_timezone_choice(input);
      }
      break;
    }
    delay(10);
  }
  Serial.println("Time zone: " + timezoneShort + " (" + timezone + ")");
}

bool apply_timezone_choice(String input) {
  int n = input.toInt();
  if (input.length() != 1 || n < 1 || n > 7) {
    Serial.println("Invalid choice, time zone not changed.");
    return false;
  }
  timezone = zoneIds[n - 1];
  timezoneShort = zoneShort[n - 1];
  timeSynced = false;   // the old clock is for another zone, so a new sync is needed
  return true;
}


// ; -------------------------------------------
// ; USB Serial Function
// ; -------------------------------------------
void check_serial() {
  if (Serial.available() == 0) {
    return;   // nothing typed
  }

  String input = Serial.readStringUntil('\n');
  input.trim();

  // Waiting for a time zone choice
  if (waitingForZone) {
    waitingForZone = false;
    if (input == "") {
      Serial.println("No input, time zone stays " + timezoneShort);
    } else if (apply_timezone_choice(input)) {
      Serial.println("Time zone is now: " + timezoneShort + " (" + timezone + ")");
      sync_time();                                   // get the time for the new zone now
      if (timeSynced) Serial.println("Current time: " + read_time());
    }
    return;
  }

  if (input == "z") {
    print_timezone_menu();
    waitingForZone = true;
  }
  else if (input == "rssi") {
    Serial.print("RSSI: ");
    Serial.print(WiFi.RSSI());
    Serial.println(" dBm");
  }
  else if (input == "h") {
    print_help();
  }
}

// ; -------------------------------------------
// ; Serial Print
// ; -------------------------------------------
void print_help() {
  Serial.println("Commands: z = time zone, rssi = signal strength, h = help");
  Serial.println("Current time zone: " + timezoneShort + " (" + timezone + ")");
}

// ; -------------------------------------------
// ; Button Function
// ; -------------------------------------------
void check_switch() {
  int state = digitalRead(BUTTON_PIN);

  if (state == BUTTON_PRESSED && lastButtonState != BUTTON_PRESSED &&
      millis() - lastButtonTime >= LOCKOUT_MS) {
    buttonEvent = true;
    lastButtonTime = millis();
    Serial.println("Button pressed -> Node_1");
  }
  lastButtonState = state;
}

// ; -------------------------------------------
// ; Tilt Switch Function
// ; -------------------------------------------
void check_tilt() {
  int state = digitalRead(TILT_PIN);

  if (state == TILT_ACTIVE && lastTiltState != TILT_ACTIVE &&
      millis() - lastTiltTime >= LOCKOUT_MS) {
    tiltEvent = true;
    lastTiltTime = millis();
    Serial.println("Tilt activated -> Node_2");
  }
  lastTiltState = state;
}


// ; -------------------------------------------
// ; Switch Function
// ; -------------------------------------------
void handle_activation() {
  if (buttonEvent) {
    buttonEvent = false;
    process_node(1);
  }
  if (tiltEvent) {
    tiltEvent = false;
    process_node(2);
  }
}

// ; -------------------------------------------
// ; Node determination function
// ; -------------------------------------------
void process_node(int node) {
  float s1, s2;

  if (!read_sensor_1(s1)) {
    Serial.println("Temperature reading is not valid, data NOT sent.");
    return;
  }
  if (!read_sensor_2(s2)) {
    Serial.println("Distance reading is not valid (no echo), data NOT sent.");
    return;
  }

  String ts = read_time();   // from the local clock, taken right after the measurement
  if (ts == "") {
    Serial.println("No time available, data NOT sent.");
    return;
  }
  Serial.println("Timestamp: " + ts);

  if (enqueue_message(node, ts, s1, s2)) {
    transmit();   // try to send everything waiting, oldest first
  }
}

// ; -------------------------------------------
// ; Thermister Sensor Function and Math
// ; -------------------------------------------
bool read_sensor_1(float &value) {
  int raw = analogRead(A0);   // 0 to 1023

  // raw of 0 or 1023 would divide by zero (sensor unplugged or shorted)
  if (raw <= 0 || raw >= 1023) {
    return false;
  }

  float resistance = 10000.0 * raw / (1023.0 - raw);
  float kelvin = 1.0 / (1.0 / 298.15 + log(resistance / 10000.0) / 3950.0);
  value = kelvin - 273.15;

  Serial.print("Sensor 1 (temperature C): ");
  Serial.println(value);

  // Same limits the server uses
  if (isnan(value) || value < -40 || value > 85) {
    return false;
  }
  return true;
}

// ; -------------------------------------------
// ; Ultra Sonic Sensor Function and Math
// ; -------------------------------------------
bool read_sensor_2(float &value) {
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);
  digitalWrite(TRIG_PIN, HIGH);        // 10 us pulse starts a measurement
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);

  long duration = pulseIn(ECHO_PIN, HIGH, 30000);   // time of the echo (0 = timeout)
  if (duration == 0) {
    return false;
  }
  value = duration * 0.0343 / 2;                    // convert to cm

  Serial.print("Sensor 2 (distance cm): ");
  Serial.println(value);

  if (value > 400) {
    return false;
  }
  return true;
}


// ; -------------------------------------------
// ; Time sync Function
// ; -------------------------------------------
bool sync_time() {
  lastSyncAttempt = millis();

  if (WiFi.status() != WL_CONNECTED) {
    return false;
  }

  WiFiClientSecure client;
  client.setInsecure();   // skip certificate check (fine for class)
  HTTPClient http;

  String url = "https://timeapi.io/api/time/current/zone?timeZone=" + timezone;
  http.begin(client, url);
  int code = http.GET();

  if (code != 200) {
    http.end();
    Serial.println("Could not get the time from timeapi.io.");
    return false;
  }

  String reply = http.getString();
  http.end();

  // The reply has something like:  "dateTime":"2026-11-09T10:15:30.123"
  int pos = reply.indexOf("\"dateTime\":\"");
  if (pos < 0) {
    Serial.println("Could not read the time from the reply.");
    return false;
  }

  int Y, M, D, h, m, s;
  String dt = reply.substring(pos + 12, pos + 31);
  if (sscanf(dt.c_str(), "%d-%d-%dT%d:%d:%d", &Y, &M, &D, &h, &m, &s) != 6) {
    Serial.println("Could not understand the time format.");
    return false;
  }

  struct tm t = {0};
  t.tm_year = Y - 1900;
  t.tm_mon = M - 1;
  t.tm_mday = D;
  t.tm_hour = h;
  t.tm_min = m;
  t.tm_sec = s;
  t.tm_isdst = 0;
  syncEpoch = mktime(&t);       // the board runs in UTC, so this is just a number of seconds
  syncMillis = millis();
  timeSynced = true;
  Serial.println("Time synced: " + dt);
  return true;
}



// ; -------------------------------------------
// ; Time Check Funtion
// ; -------------------------------------------
void check_time_sync() {
  if (timeSynced && millis() - lastSyncAttempt >= RESYNC_MS) {
    sync_time();
  }
}


// ; -------------------------------------------
// ; Time Read Funtion
// ; -------------------------------------------
String read_time() {
  if (!timeSynced) {
    sync_time();
  }
  if (!timeSynced) {
    return "";
  }

  time_t now = syncEpoch + (millis() - syncMillis) / 1000;
  struct tm t;
  gmtime_r(&now, &t);
  char buf[20];
  strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M:%S", &t);
  return String(buf);
}



// ; -------------------------------------------
// ; Queue Function
// ; -------------------------------------------
bool enqueue_message(int node, String ts, float s1, float s2) {
  if (queueCount >= QUEUE_SIZE) {
    Serial.println("Queue is full, this measurement was lost.");
    return false;
  }
  queueBuf[queueCount].node = node;
  queueBuf[queueCount].ts = ts;
  queueBuf[queueCount].s1 = s1;
  queueBuf[queueCount].s2 = s2;
  queueCount++;
  return true;
}

void dequeue_message() {
  for (int i = 1; i < queueCount; i++) {
    queueBuf[i - 1] = queueBuf[i];
  }
  queueCount--;
}


// ; -------------------------------------------
// ; Transmission Function
// ; -------------------------------------------
SendResult send_message(const Message &m) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi is not connected.");
    return SEND_RETRY;
  }

  // Spaces are not allowed in a URL, so replace them
  String ts = m.ts;
  ts.replace(" ", "%20");

  // Qurry set up
  String url = serverURL + "?nodeId=node_" + String(m.node)
             + "&timeReceived=" + ts
             + "&nodeTemp=" + String(m.s1, 2)
             + "&nodeDistance=" + String(m.s2, 2);

  Serial.println("Sending: " + url);
  Serial.print("RSSI: ");
  Serial.print(WiFi.RSSI());
  Serial.println(" dBm");

  WiFiClientSecure client;
  client.setInsecure();   // the website uses https
  HTTPClient http;
  http.begin(client, url);
  http.setTimeout(8000);
  int code = http.GET();
  String reply = (code > 0) ? http.getString() : "";
  http.end();

// ; -------------------------------------------
// ; PHP Success/Fail Code Receive Function
// ; -------------------------------------------
  Serial.print("Server code: ");
  Serial.println(code);

  // The PHP page shows its result between <p><b> and </b>.
  int start = reply.indexOf("<p><b>");
  int end = reply.indexOf("</b>", start);
  if (start >= 0 && end > start) {
    Serial.println("Server reply: " + reply.substring(start + 6, end));
  }

  if (code == 200) {
    return SEND_OK;
  }
  if (code == 409) {
    Serial.println("Server already has this message, counted as delivered.");
    return SEND_OK;
  }
  if (code == 400 || code == 403) {
    Serial.println("Server rejected this message for good, it will NOT be retried.");
    return SEND_REJECTED;
  }
  return SEND_RETRY;   // -1 (no connection), 5xx, anything unexpected
}

// ; -------------------------------------------
// ; Retransmission Function
// ; -------------------------------------------.
void transmit() {
  while (queueCount > 0) {
    SendResult result = send_message(queueBuf[0]);

    if (result == SEND_RETRY) {
      Serial.println("Send failed, will retry in 30 seconds.");
      lastRetryTime = millis();
      return;
    }
    if (result == SEND_OK) {
      Serial.println("Data sent successfully!");
    }
    dequeue_message();   // delivered or rejected for good: remove it
  }
}

void check_error() {
  if (queueCount > 0 && millis() - lastRetryTime >= RETRY_INTERVAL_MS) {
    lastRetryTime = millis();
    Serial.print("Retrying saved messages: ");
    Serial.println(queueCount);
    transmit();
  }
}