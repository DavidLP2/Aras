ALTER TABLE `orders`
    MODIFY COLUMN `city` VARCHAR(120) NOT NULL,
    ADD COLUMN `delivery_zone` ENUM('medellin', 'antioquia') NOT NULL DEFAULT 'medellin' AFTER `city`,
    ADD COLUMN `delivery_latitude` DECIMAL(10,7) NULL AFTER `delivery_address`,
    ADD COLUMN `delivery_longitude` DECIMAL(10,7) NULL AFTER `delivery_latitude`;
