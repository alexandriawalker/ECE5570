-- Part 1
-- 1.1.B

-- Create a table
CREATE TABLE Book(  
id INT NOT NULL AUTO_INCREMENT,  
name VARCHAR(100) NOT NULL,  
PRIMARY KEY (id)); 

SHOW DATABASES;

USE u209446640_SSUIoT;

DROP DATABASE Book

-- Create a table
CREATE TABLE Book(  
id INT NOT NULL AUTO_INCREMENT,  
name VARCHAR(100) NOT NULL,  
PRIMARY KEY (id)); 

SELECT * from Book;

-- 1.2 

DROP DATABASE Book

-- Followed Farid's tutorial, don't feel the need to copy and paste what is on his GitHub here

-- 1.3 

CREATE TABLE sensor_register (
    node_name    VARCHAR(10)    NOT NULL, -- PRIMARY KEY
    manufacturer VARCHAR(10)    NOT NULL, -- Limiting sensor manufacturer to 10 characters
    longitude    DECIMAL(15,12) NOT NULL, -- Data for longitude with a maximum of 15 positions
    latitude     DECIMAL(15,12) NOT NULL, -- Data for latitude with a maximum of 15 positions
    PRIMARY KEY (node_name),
    CHECK (longitude BETWEEN -180 AND 180), -- Restricting values to between -180 to +180 to block unrealistic logitudes
    CHECK (latitude  BETWEEN -90  AND 90)   -- Restricting values to between -90 to +90 to block unrealistic latitudes
);

CREATE TABLE sensor_data (
    node_name     VARCHAR(10)  NOT NULL, -- Apart of PRIMARY KEY
    time_received DATETIME     NOT NULL, -- Apart of PRIMARY KEY
    temperature   DECIMAL(6,2) NOT NULL, -- Data for temperature with a maximum of 6 positions
    humidity      DECIMAL(6,2) NOT NULL, -- Data for humidity with a maximum of 6 positions
    PRIMARY KEY (node_name, time_received),
    FOREIGN KEY (node_name) REFERENCES sensor_register(node_name),
    CHECK (temperature BETWEEN -10 AND 100), -- Restricting values to between -10 to +100 celsius
    CHECK (humidity    BETWEEN 0   AND 100)  -- Restricting values to between 0 to +100 Celsius
);



-- Just picked five close cities from SSU to use as the location
INSERT INTO sensor_register (node_name, manufacturer, longitude, latitude) VALUES
('node_1', 'adafruit',   -122.701100000000, 38.339600000000),  -- Rohnert Park
('node_2', 'hiletgo',    -122.710800000000, 38.327700000000),  -- Cotati
('node_3', 'elegoo',     -122.669700000000, 38.313600000000),  -- Penngrove
('node_4', 'wwzmdib',    -122.714400000000, 38.440500000000),  -- Santa Rosa
('node_5', 'MakerFocus', -122.546100000000, 38.413500000000);  -- Kenwood


-- All of these are randomly selected number. Not real sensor data
INSERT INTO sensor_data (node_name, time_received, temperature, humidity) VALUES
('node_1', '2022-10-01 11:00:00', 21.50, 45.00),
('node_1', '2022-10-01 11:30:00', 21.80, 44.50),
('node_1', '2022-10-01 12:00:00', 22.10, 44.00),
('node_1', '2022-10-01 12:30:00', 22.40, 43.20),

('node_2', '2022-10-01 11:00:00', 19.00, 55.00),
('node_2', '2022-10-01 11:30:00', 19.30, 54.20),
('node_2', '2022-10-01 12:00:00', 19.70, 53.80),
('node_2', '2022-10-01 12:30:00', 20.00, 53.00),

('node_3', '2022-10-01 11:00:00', 23.40, 38.00),
('node_3', '2022-10-01 11:30:00', 23.60, 37.50),
('node_3', '2022-10-01 12:00:00', 23.90, 37.00),
('node_3', '2022-10-01 12:30:00', 24.20, 36.40),

('node_4', '2022-10-01 11:00:00', 17.80, 62.00),
('node_4', '2022-10-01 11:30:00', 18.00, 61.30),
('node_4', '2022-10-01 12:00:00', 18.30, 60.90),
('node_4', '2022-10-01 12:30:00', 18.60, 60.10),

('node_5', '2022-10-01 11:00:00', 25.10, 30.00),
('node_5', '2022-10-01 11:30:00', 25.40, 29.50),
('node_5', '2022-10-01 12:00:00', 25.70, 29.10),
('node_5', '2022-10-01 12:30:00', 26.00, 28.60);



CREATE VIEW sensor_combined AS
SELECT d.node_name,
       d.time_received,
       d.temperature,
       d.humidity,
       r.manufacturer,
       r.longitude,
       r.latitude
FROM sensor_data d
JOIN sensor_register r ON d.node_name = r.node_name;


SELECT * FROM sensor_combined ORDER BY node_name, time_received;

INSERT INTO sensor_data (node_name, time_received, temperature, humidity)
VALUES ('node_99', '2025-10-01 11:00:00', 199, 95.00);
