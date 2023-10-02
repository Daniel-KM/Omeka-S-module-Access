CREATE TABLE `access_request` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `user_id` INT DEFAULT NULL,
    `email` VARCHAR(190) DEFAULT NULL,
    `token` VARCHAR(16) DEFAULT NULL,
    `status` VARCHAR(8) DEFAULT 'new' NOT NULL,
    `recursive` TINYINT(1) DEFAULT '0' NOT NULL,
    `enabled` TINYINT(1) DEFAULT '0' NOT NULL,
    `temporal` TINYINT(1) DEFAULT '0' NOT NULL,
    `start` DATETIME DEFAULT NULL,
    `end` DATETIME DEFAULT NULL,
    `name` VARCHAR(190) DEFAULT NULL,
    `message` LONGTEXT DEFAULT NULL,
    `fields` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    `created` DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    `modified` DATETIME DEFAULT NULL,
    INDEX IDX_F3B2558AA76ED395 (`user_id`),
    INDEX IDX_F3B2558A5F37A13B (`token`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE `access_resource` (
    `access_request_id` INT NOT NULL,
    `resource_id` INT NOT NULL,
    INDEX IDX_D184352768402024 (`access_request_id`),
    INDEX IDX_D184352789329D25 (`resource_id`),
    PRIMARY KEY(access_request_id, resource_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE `access_log` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `user_id` INT NOT NULL,
    `access_id` INT NOT NULL,
    `access_type` VARCHAR(7) NOT NULL,
    `action` VARCHAR(31) NOT NULL,
    `date` DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE `access_status` (
    `id` INT NOT NULL,
    `level` VARCHAR(15) NOT NULL,
    `embargo_start` DATETIME DEFAULT NULL,
    `embargo_end` DATETIME DEFAULT NULL,
    INDEX IDX_898BF02E9AEACC13 (`level`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE `access_request` ADD CONSTRAINT FK_F3B2558AA76ED395 FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
ALTER TABLE `access_resource` ADD CONSTRAINT FK_D184352768402024 FOREIGN KEY (`access_request_id`) REFERENCES `access_request` (`id`) ON DELETE CASCADE;
ALTER TABLE `access_resource` ADD CONSTRAINT FK_D184352789329D25 FOREIGN KEY (`resource_id`) REFERENCES `resource` (`id`) ON DELETE CASCADE;
ALTER TABLE `access_status` ADD CONSTRAINT FK_898BF02EBF396750 FOREIGN KEY (`id`) REFERENCES `resource` (`id`) ON DELETE CASCADE;
