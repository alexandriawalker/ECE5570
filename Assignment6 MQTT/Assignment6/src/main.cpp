// ;--------------------------------------------
// ; Title: ESP Programming and MQTT
// ;--------------------------------------------
// ; Program Detail:
// ;--------------------------------------------
// ; Purpose: ESP8266 publishes a potentiometer value and a button state to a
// ;          HiveMQ broker and subscribes to a topic that turns an LED on/off.
// ;
// ; Inputs: A0 - Potentiometer wiper, D6 - Button, USB serial
// ; Outputs: D7 - LED, USB terminal, MQTT publishes
// ; Date: 11/15/2026
// ; Compiler: VS Code with PlatformIO
// ; Author: Alexandria Walker
// ; Versions:
// ;           V1 - Pot value, button and LED tested on the serial terminal
// ;           V2 - Added WiFi and MQTT connection, pot published every 15 s
// ;           V3 - Added subscribe to inTopic for LED control
// ;           V4 - Button publishes 1, then 0 after 5 s 
// ;           V5 - Added LED state topic for the dashboard 
// ;           V6 - Moved to private HiveMQ Cloud cluster with username/password
// ;           V7 - Pot now reported in ohms, button moved to its own topic
// ;

// ; -------------------------------------------
// ; File Dependancies
// ; -------------------------------------------
#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>      // PlatformIO: lib_deps = knolleary/PubSubClient

// ; -------------------------------------------
// ; WiFi Connection
// ; -------------------------------------------
const char* ssid = "ECE570";
const char* password = "PASSWORD";

// ; -------------------------------------------
// ; MQTT Broker Connection
// ; -------------------------------------------
const char* mqttServer = "ece570-iot-aw-2cb59fde.a02.usw2.aws.hivemq.cloud";   
const int   mqttPort   = 8883;                       
const char* mqttUser   = "AWECE570iot";     
const char* mqttPass   = "PASSWORD";    

// ; -------------------------------------------
// ; MQTT Topics  (change AW01 to your unique code)
// ; -------------------------------------------
const char* potTopic    = "testtopic/temp/outTopic/AW_4";     // pot resistance in ohms, every 15 s
const char* buttonTopic = "testtopic/temp/outTopic/AW_3";     // button 1 / 0
const char* ledInTopic  = "testtopic/temp/inTopicAW_4";       // subscribe: 1 = LED on, 0 = LED off
const char* ledStateTopic = "testtopic/temp/ledState/AW_4";   // LED state for the dashboard

// ; -------------------------------------------
// ; Definitions
// ; -------------------------------------------
// ; ESP Pins
#define POT_PIN    A0       
#define BUTTON_PIN D6
#define LED_PIN    D7
// ; Button default setting (button module output goes HIGH when pressed)
#define BUTTON_PRESSED HIGH
// ; Timing
#define LOCKOUT_MS         50UL      // button debounce time
#define PUBLISH_INTERVAL   15000UL   // publish the pot every 15 s 
#define BUTTON_HOLD_MS     5000UL    // send the 0 this long after the 1
#define RECONNECT_MS       5000UL    // wait between MQTT reconnect attempts
#define WIFI_TIMEOUT_MS    20000UL   // give each WiFi attempt this long before reporting why it failed
// ; ADC
#define ADC_MAX            1023.0
// ; Pot total resistance, measured with a DMM across the two ends of the track (a 200k pot)
#define POT_TOTAL_OHMS     195000.0

// ; -------------------------------------------
// ; Objects
// ; -------------------------------------------
WiFiClientSecure espClient;
PubSubClient client(espClient);

// ; -------------------------------------------
// ; States
// ; -------------------------------------------
int lastButtonState;
unsigned long lastButtonTime = 0;
bool buttonEvent = false;           // set by check_switch(), used by handle_button()
bool buttonActive = false;          // true while we are waiting to send the 0
unsigned long buttonSentTime = 0;

bool ledState = false;
unsigned long lastPublishTime = 0;
unsigned long lastReconnectTime = 0;

// ; -------------------------------------------
// ; Function Declarations
// ; -------------------------------------------
void initialize_serial();
void initialize_pins();
void initialize_wifi();
void print_wifi_status(int status);
void scan_networks();
void initialize_mqtt();
void check_mqtt();
bool reconnect();
void callback(char* topic, byte* payload, unsigned int length);
void check_serial();
void set_led(bool on);
void check_switch();
void handle_button();
float read_pot_ohms(int &raw);
void publish_pot();
void print_help();

// ; -------------------------------------------
// ; Set Up
// ; -------------------------------------------
void setup() {
  initialize_serial();      // first, so the other functions can print messages
  initialize_pins();
  initialize_wifi();
  initialize_mqtt();
  print_help();
}

// ; -------------------------------------------
// ; Loop Function that only calls other functions
// ; -------------------------------------------
void loop() {
  check_mqtt();       // stay connected, and receive messages (LED control)
  check_serial();     // typing 1 or 0 in the terminal controls the LED
  check_switch();     // button pressed?
  handle_button();    // publish 1 now, publish 0 after 5 s
  publish_pot();      // publish the pot every 15 s
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
// ; Pin Function
// ; -------------------------------------------
void initialize_pins() {
  pinMode(LED_PIN, OUTPUT);
  pinMode(BUTTON_PIN, INPUT_PULLUP);   // harmless if the module drives the pin itself
  digitalWrite(LED_PIN, LOW);
  lastButtonState = digitalRead(BUTTON_PIN);
}

// ; -------------------------------------------
// ; WiFi Function
// ; -------------------------------------------
void initialize_wifi() {
  WiFi.persistent(false);        // do not reuse old saved WiFi settings from flash
  WiFi.mode(WIFI_STA);
  WiFi.disconnect();
  delay(100);

  while (WiFi.status() != WL_CONNECTED) {
    Serial.print("Connecting to Wi-Fi: ");
    Serial.println(ssid);
    WiFi.begin(ssid, password);

    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < WIFI_TIMEOUT_MS) {
      delay(500);
      Serial.print(".");
    }
    Serial.println();

    if (WiFi.status() != WL_CONNECTED) {
      print_wifi_status(WiFi.status());
      scan_networks();
      WiFi.disconnect();
      delay(1000);               // then try again
    }
  }

  Serial.print("Connected! IP address: ");
  Serial.println(WiFi.localIP());
  Serial.print("RSSI: ");
  Serial.print(WiFi.RSSI());
  Serial.println(" dBm");
}

// ; -------------------------------------------
// ; WiFi Status Print (tells you why it failed)
// ; -------------------------------------------
void print_wifi_status(int status) {
  Serial.print("WiFi failed, status ");
  Serial.print(status);
  Serial.print(": ");
  switch (status) {
    case WL_NO_SSID_AVAIL:   Serial.println("network name not found (check ssid, 2.4 GHz only, hotspot on)"); break;
    case WL_CONNECT_FAILED:  Serial.println("connection failed (usually a wrong password)"); break;
    case WL_WRONG_PASSWORD:  Serial.println("wrong password"); break;
    case WL_DISCONNECTED:    Serial.println("not connected (router did not accept the ESP)"); break;
    case WL_IDLE_STATUS:     Serial.println("still trying"); break;
    default:                 Serial.println("unknown"); break;
  }
}

// ; -------------------------------------------
// ; WiFi Scan (shows what 2.4 GHz networks the ESP can see)
// ; -------------------------------------------
void scan_networks() {
  Serial.println("Scanning for networks...");
  int n = WiFi.scanNetworks();
  if (n <= 0) {
    Serial.println("No networks found.");
    return;
  }
  for (int i = 0; i < n; i++) {
    Serial.print("  ");
    Serial.print(WiFi.SSID(i));
    Serial.print("  (");
    Serial.print(WiFi.RSSI(i));
    Serial.println(" dBm)");
  }
}

// ; -------------------------------------------
// ; MQTT Set Up Function
// ; -------------------------------------------
void initialize_mqtt() {
  espClient.setInsecure();                 // skip certificate check (fine for class)
  client.setServer(mqttServer, mqttPort);
  client.setCallback(callback);
}

// ; -------------------------------------------
// ; MQTT Connection Function
// ; -------------------------------------------
void check_mqtt() {
  if (!client.connected()) {
    if (millis() - lastReconnectTime >= RECONNECT_MS || lastReconnectTime == 0) {
      lastReconnectTime = millis();
      reconnect();
    }
    return;
  }
  client.loop();   // receives messages and keeps the connection alive
}

bool reconnect() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi is not connected.");
    return false;
  }

  Serial.print("Attempting MQTT connection...");
  // Create a random client ID
  String clientId = "ESP8266Client-";
  clientId += String(random(0xffff), HEX);

  bool ok;
  if (String(mqttUser) == "") {
    ok = client.connect(clientId.c_str());
  } else {
    ok = client.connect(clientId.c_str(), mqttUser, mqttPass);
  }

  if (ok) {
    Serial.println("connected");
    client.subscribe(ledInTopic);          // resubscribe every time we reconnect
    client.publish(ledStateTopic, ledState ? "1" : "0");
    return true;
  }

  Serial.print("failed, rc=");
  Serial.print(client.state());
  Serial.println(" will try again in 5 seconds");
  return false;
}

// ; -------------------------------------------
// ; MQTT Receive Function (LED control)
// ; -------------------------------------------
void callback(char* topic, byte* payload, unsigned int length) {
  String msg = "";
  for (unsigned int i = 0; i < length; i++) {
    msg += (char)payload[i];
  }
  msg.trim();

  Serial.print("Message arrived [");
  Serial.print(topic);
  Serial.print("]: ");
  Serial.println(msg);

  if (msg == "1") {
    set_led(true);
  } else if (msg == "0") {
    set_led(false);
  } else {
    Serial.println("Unknown command, send 1 or 0.");
  }
}

// ; -------------------------------------------
// ; LED Function
// ; -------------------------------------------
void set_led(bool on) {
  ledState = on;
  digitalWrite(LED_PIN, on ? HIGH : LOW);
  Serial.println(on ? "LED ON" : "LED OFF");
  if (client.connected()) {
    client.publish(ledStateTopic, on ? "1" : "0");   // dashboard shows the LED state
  }
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

  if (input == "1") {
    set_led(true);
  }
  else if (input == "0") {
    set_led(false);
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
  Serial.println("Commands: 1 = LED on, 0 = LED off, rssi = signal strength, h = help");
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
    Serial.println("Button pressed: 1");
  }
  lastButtonState = state;
}

// ; -------------------------------------------
// ; Button Publish Function (1 now, 0 after 5 s)
// ; -------------------------------------------
void handle_button() {
  // Button just pressed -> send a 1
  if (buttonEvent) {
    buttonEvent = false;
    if (client.connected()) {
      client.publish(buttonTopic, "1");
      Serial.println("Published 1 to " + String(buttonTopic));
    } else {
      Serial.println("MQTT not connected, button value NOT sent.");
    }
    buttonActive = true;           // the 0 is still owed, even if the 1 was lost
    buttonSentTime = millis();
  }

  // 5 seconds later -> send the 0 (no delay(), the rest of the code keeps running)
  if (buttonActive && millis() - buttonSentTime >= BUTTON_HOLD_MS) {
    if (client.connected()) {
      client.publish(buttonTopic, "0");
      Serial.println("Published 0 to " + String(buttonTopic));
    }
    buttonActive = false;
  }
}

// ; -------------------------------------------
// ; Potentiometer Function and Math
// ; -------------------------------------------
// Returns the resistance between the wiper and GND (the part that grows with the voltage).
float read_pot_ohms(int &raw) {
  raw = analogRead(POT_PIN);                      // 0 to 1023
  return (raw / ADC_MAX) * POT_TOTAL_OHMS;        // ohms
}

// ; -------------------------------------------
// ; Potentiometer Publish Function
// ; -------------------------------------------
void publish_pot() {
  if (millis() - lastPublishTime < PUBLISH_INTERVAL) {
    return;
  }
  lastPublishTime = millis();

  int raw;
  float ohms = read_pot_ohms(raw);
  Serial.print("Potentiometer: raw = ");
  Serial.print(raw);
  Serial.print(", ohms = ");
  Serial.println(ohms, 0);

  if (!client.connected()) {
    Serial.println("MQTT not connected, pot value NOT sent.");
    return;
  }

  char payload[12];
  dtostrf(ohms, 1, 0, payload);              // whole ohms, e.g. "129421"
  client.publish(potTopic, payload);
  Serial.println("Published " + String(payload) + " to " + String(potTopic));
}
