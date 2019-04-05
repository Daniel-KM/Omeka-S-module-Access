CREATE TABLE access_resource (
    id INT AUTO_INCREMENT NOT NULL,
    resource_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    token VARCHAR(255) DEFAULT NULL,
    enabled TINYINT(1) DEFAULT '0' NOT NULL,
    temporal TINYINT(1) DEFAULT '0' NOT NULL,
    start_date DATETIME DEFAULT NULL,
    end_date DATETIME DEFAULT NULL,
    created DATETIME NOT NULL,
    modified DATETIME DEFAULT NULL,
    INDEX IDX_D184352789329D25 (resource_id),
    INDEX IDX_D1843527A76ED395 (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE access_request (
    id INT AUTO_INCREMENT NOT NULL,
    resource_id INT NOT NULL,
    user_id INT NOT NULL,
    status VARCHAR(255) DEFAULT 'new' NOT NULL,
    created DATETIME NOT NULL,
    modified DATETIME DEFAULT NULL,
    INDEX IDX_F3B2558A89329D25 (resource_id),
    INDEX IDX_F3B2558AA76ED395 (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE access_log (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    record_id INT NOT NULL,
    type VARCHAR(255) NOT NULL,
    date DATETIME DEFAULT NULL,
    INDEX IDX_EF7F3510A76ED395 (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE access_resource ADD CONSTRAINT FK_D184352789329D25 FOREIGN KEY (resource_id) REFERENCES resource (id) ON DELETE CASCADE;
ALTER TABLE access_resource ADD CONSTRAINT FK_D1843527A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL;
ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558A89329D25 FOREIGN KEY (resource_id) REFERENCES resource (id) ON DELETE CASCADE;
ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
ALTER TABLE access_log ADD CONSTRAINT FK_EF7F3510A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL;
