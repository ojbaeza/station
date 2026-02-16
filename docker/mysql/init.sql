-- Station MySQL Initialization Script

-- Create testing database
CREATE DATABASE IF NOT EXISTS station_testing;

-- Grant permissions
GRANT ALL PRIVILEGES ON station.* TO 'station'@'%';
GRANT ALL PRIVILEGES ON station_testing.* TO 'station'@'%';

FLUSH PRIVILEGES;

-- Station tables will be created via Laravel migrations
-- This file is for initial setup only
