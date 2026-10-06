// ;--------------------------------------------
// ; Title: ADC Battery Charge/Discharge Monitor
// ;--------------------------------------------
// ; Program Detail:
// ;--------------------------------------------
// ; Purpose: Measure the battery voltage once every 60 seconds for 10 hours. This code is used for both the 
// ;          charging and discharging phase of the project. This code calculates how much voltage was stepped down, using the 
// ;          on board voltage divider and the external voltage divider. Then the code takes the raw ADC reading and converts it into 
// ;          a voltage based on the resolution of the ADC. The final battery measurement is the product of the ADC reading and the
// ;          ratio of the voltage dividers. 
// ; Inputs: ADC (A0) - Voltage Input
// ; Outputs: Battery Voltage terminal output
// ; Date: 10/06/2026
// ; Compiler: VS Code with PlatformIO
// ; Author: Alexandria Walker
// ; Versions:
// ;           V1 - Initial File to measure the voltage with an external voltage divider of 100k/100k
// ;           V2 - Changed math for the battey voltage as there is a voltage divider on the board itself.
// ;           V3 - Made a calibration adjustment and added it to the math for the battery voltage
// ;           V4 - Added an averaging such that every 60 seconds, it takes the average of 3 measurements and stops running 
// ;                afte taking 600 samples (Or 10 hours)
// ;

// ; -------------------------------------------
// ; File Dependancies
// ; -------------------------------------------

#include <Arduino.h>

// ; -------------------------------------------
// ; Program Variables
// ; -------------------------------------------

const int           NUM_SAMPLES = 600;     // 10 hours at 1 measurement per minute
const unsigned long INTERVAL_MS = 60000;   // 60 seconds
const int           ADC_AVG     = 3;       // readings averaged per measurement
const float R_TOP            = 100000.0;   // external top resistor (ohms)
const float R_BOTTOM         = 100000.0;   // external bottom resistor (ohms)
const float R_ONBOARD_TOP    = 220000.0;   // NodeMCU onboard divider
const float R_ONBOARD_BOTTOM = 100000.0;
const float CAL = 3.22 / 3.400;   // Offset Correction

// ;--------------------------------------------
// ; Main Program
// ;--------------------------------------------

// This section maths out how much the voltage
// was scaled down before reaching the ADC
float batteryScale() {
  float rLoad      = R_ONBOARD_TOP + R_ONBOARD_BOTTOM;
  float rBottomEff = (R_BOTTOM * rLoad) / (R_BOTTOM + rLoad);
  float extRatio   = rBottomEff / (R_TOP + rBottomEff);
  float boardRatio = R_ONBOARD_BOTTOM / (R_ONBOARD_TOP + R_ONBOARD_BOTTOM);
  return 1.0 / (extRatio * boardRatio);
}

// this is the averaging math for 3 measurements
float readRawAvg() {
  long sum = 0;
  for (int i = 0; i < ADC_AVG; i++) {
    sum += analogRead(A0);
    delay(10);
  }
  return (float)sum / ADC_AVG;
}

void setup() {
  Serial.begin(9600);
  delay(1000);

  const float scale = batteryScale();
  Serial.println("\nADC battery charge measurement started");

  unsigned long startTime = millis(); // Starts the timer

  for (int i = 0; i < NUM_SAMPLES; i++) { // Counts up to 600 (10 Hours)
    unsigned long target = startTime + (unsigned long)i * INTERVAL_MS;
    while (millis() < target) {
      yield();
    }

    float raw      = readRawAvg();          // average of 3 ADC readings
    float adcV  = raw / 1023.0;             // Converts ADC reading into a voltage
    float batteryV = adcV * scale * CAL;    // Calculates the batery voltage

    Serial.printf("Measurement %d/%d: raw=%.1f  %.3f V\n", i + 1, NUM_SAMPLES, raw, batteryV);
  }

  Serial.println("Done.");
}

void loop() {}
