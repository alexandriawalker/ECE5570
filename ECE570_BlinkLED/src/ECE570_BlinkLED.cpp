// ;--------------------------------------------
// ; Title: 
// ;--------------------------------------------
// ; Program Detail:
// ;--------------------------------------------
// ; Purpose:
// ; Inputs:
// ; Outputs:
// ; Date: 
// ; Compiler: VS Code with PlatformIO
// ; Author: Alexandria Walker
// ; Versions:
// ;           V1 - Initial Blink LED program example to ensur board can connect
// ;           V2 - Added the part of the code where the LED ON and LED Off is printed in the temrinal (fixed the reversed LED printing on the terminal)
// ;

// ; -------------------------------------------
// ; File Dependancies
// ; -------------------------------------------

#include <Arduino.h>

// ;--------------------------------------------
// ; Main Program
// ;--------------------------------------------

void setup() {
  pinMode(LED_BUILTIN, OUTPUT);     // Initialize the LED_BUILTIN pin as an output
  Serial.begin(9600);
}


// the loop function runs over and over again forever
void loop() {
  digitalWrite(LED_BUILTIN, LOW);   // Turn the LED on (Note that LOW is the voltage level
  // but actually the LED is on; this is because
  // it is active low on the ESP-01)
  Serial.println("LED is ON");
  delay(1000);                      // Wait for a second
  digitalWrite(LED_BUILTIN, HIGH);  // Turn the LED off by making the voltage HIGH
  Serial.println("LED is OFF");
  delay(2000);                      // Wait for two seconds (to demonstrate the active low LED)
}