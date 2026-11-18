# --------------------------------------------
# Title: MQTT Relay Node (HiveMQ -> Hostinger MySQL)
# --------------------------------------------
# Purpose: Subscribe to the ESP potentiometer topic on HiveMQ, print every
#          new value, and push it into the potentiometer table. The database
#          timestamps each row. If this program is stopped, nothing is
#          written to the database.
# Install: pip install paho-mqtt mysql-connector-python
# Author:  Alexandria Walker
# --------------------------------------------

import paho.mqtt.client as mqtt
import mysql.connector

# ---------------- MQTT settings ----------------
BROKER_URL = "ece570-iot-aw-2cb59fde.a02.usw2.aws.hivemq.cloud"   # HiveMQ Cloud cluster (host only)
BROKER_PORT = 8883                      # secure MQTT (TLS)
MQTT_USER = "AWECE570iot"      # from cluster -> Access Management
MQTT_PASS = "ECE570iot"      # (NOT your HiveMQ account login)
TOPIC = "testtopic/temp/outTopic/AW_4"  # must match potTopic in the ESP code

# ---------------- Database settings ----------------
# Hostinger: hPanel -> Databases -> Remote MySQL, add this computer's IP first.
HOST = "31.97.208.95"       # Hostinger database IP / hostname
USER = "u223090689_db_AlexWalker"
PASSWORD = "ECE570iot"
DATABASE = "u223090689_AlexWalker"


def push_value_to_db(potentiometer):
    connection = None
    cursor = None
    try:
        connection = mysql.connector.connect(
            host=HOST, user=USER, password=PASSWORD, database=DATABASE
        )
        cursor = connection.cursor()
        insert_query = "INSERT INTO potentiometer (value) VALUES (%s)"
        cursor.execute(insert_query, (potentiometer,))
        connection.commit()
        print(f"Value {potentiometer} inserted into potentiometer table!")
    except mysql.connector.Error as err:
        print(f"Database error: {err}")
    finally:
        if cursor is not None:
            cursor.close()
        if connection is not None and connection.is_connected():
            connection.close()


def on_connect(client, userdata, flags, rc, properties=None):
    if rc == 0:
        print("Connected to HiveMQ broker!")
        client.subscribe(TOPIC)
        print(f"Subscribed to topic: {TOPIC}")
    else:
        print(f"Failed to connect, return code {rc}")


def on_message(client, userdata, msg):
    payload = msg.payload.decode().strip()
    print(f"Received message: {payload} from topic: {msg.topic}")

    try:
        value = float(payload)
    except ValueError:
        print("Not a number, not stored.")
        return

    push_value_to_db(value)


# paho-mqtt 2.x needs the callback API version, 1.x does not
try:
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
except AttributeError:
    client = mqtt.Client()

client.tls_set()                                   # TLS is required on port 8883
client.username_pw_set(MQTT_USER, MQTT_PASS)
client.on_connect = on_connect
client.on_message = on_message

print("Connecting to broker...")
client.connect(BROKER_URL, BROKER_PORT, 60)
client.loop_forever()
