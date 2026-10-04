// ;--------------------------------------------
// ; Title: 
// ;--------------------------------------------
// ; Program Detail:
// ;--------------------------------------------
// ; Purpose: The purpose of this program is to validate the ADC that is on the ESP8266 devopment board to ensure it is 
// ; appropriet for the discharging and charging expirement. 
// ; Inputs: ADC (0) - Voltage Input
// ; Outputs: Battery Voltage terminal output
// ; Date: 10/04/2026
// ; Compiler: VS Code with PlatformIO
// ; Author: Alexandria Walker
// ; Versions:
// ;           V1 - Initial File to measure the voltage with an external voltage divider of 100k/100k
// ;           V2 - Changed math for the battey voltage as there is a voltage divider on the board itself.
// ;           V3 - Made a calibration adjustment and added it to the math for the battery voltage
// ;

// ; -------------------------------------------
// ; File Dependancies
// ; -------------------------------------------

#include <Arduino.h>

// ; -------------------------------------------
// ; Program Variables
// ; -------------------------------------------

const int           NUM_SAMPLES = 20;
const unsigned long INTERVAL_MS = 30000;   // 30 seconds
const float R_TOP            = 100000.0;   // external top resistor (ohms)
const float R_BOTTOM         = 100000.0;   // external bottom resistor (ohms)
const float R_ONBOARD_TOP    = 220000.0;   // NodeMCU onboard divider
const float R_ONBOARD_BOTTOM = 100000.0;
const float CAL = 3.22 / 3.400;   // Offset Correction

// ;--------------------------------------------
// ; Main Program
// ;--------------------------------------------


// ADC pin voltage -> Battery voltage math
float batteryScale() {
  float rLoad      = R_ONBOARD_TOP + R_ONBOARD_BOTTOM;
  float rBottomEff = (R_BOTTOM * rLoad) / (R_BOTTOM + rLoad);
  float extRatio   = rBottomEff / (R_TOP + rBottomEff);
  float boardRatio = R_ONBOARD_BOTTOM / (R_ONBOARD_TOP + R_ONBOARD_BOTTOM);
  return 1.0 / (extRatio * boardRatio);
}

void setup() {
  Serial.begin(9600);
  delay(1000);

  const float scale = batteryScale();
  Serial.println("\nADC battery measurement started");

  unsigned long startTime = millis();

  for (int i = 0; i < NUM_SAMPLES; i++) {
    unsigned long target = startTime + (unsigned long)i * INTERVAL_MS;
    while (millis() < target) {
      yield();
    }

    int   raw      = analogRead(A0);
    float adcPinV  = raw / 1023.0;          // ADC full scale is 1.0 V
    float batteryV = adcPinV * scale * CAL;

    Serial.printf("Measurement %d/%d: raw=%d  %.3f V\n", i + 1, NUM_SAMPLES, raw, batteryV);
  }

  Serial.println("Done.");
}

void loop() {}