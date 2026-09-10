#define REED_PIN 16  

int lastState = HIGH;
unsigned long lastTriggerTime = 0;
const unsigned long debounceDelay = 50; // ms

void setup() {
  Serial.begin(9600);
  pinMode(REED_PIN, INPUT_PULLUP);
  Serial.println("Reed sensor ready on D1...");
}

void loop() {
  int currentState = digitalRead(REED_PIN);

  // Detect the moment it changes from open -> closed (HIGH -> LOW)
  if (currentState == LOW && lastState == HIGH) {
    if (millis() - lastTriggerTime > debounceDelay) {
      Serial.println("Switch triggered");
      lastTriggerTime = millis();
    }
  }

  lastState = currentState;

  // Add any other code here — this loop never blocks or stops.
}