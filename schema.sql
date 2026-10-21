-- Tool Manager - Modern Enterprise Workflow & Project Management Tool
-- Database Initialization Schema

CREATE DATABASE IF NOT EXISTS `kanban_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kanban_db`;

-- Drop existing tables if re-initializing (in reverse order of foreign keys)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `comments`;
DROP TABLE IF EXISTS `task_assignments`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `columns`;
DROP TABLE IF EXISTS `project_members`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users Table
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `avatar_color` VARCHAR(20) DEFAULT '#6366f1',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Projects Table
CREATE TABLE `projects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `owner_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_projects_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_projects_owner` (`owner_id`),
    INDEX `idx_projects_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Project Members (Composite PK)
CREATE TABLE `project_members` (
    `project_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `role` ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`project_id`, `user_id`),
    CONSTRAINT `fk_pm_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_pm_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Columns Table (Board Columns)
CREATE TABLE `columns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `name` VARCHAR(60) NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_columns_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    INDEX `idx_columns_project_pos` (`project_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tasks Table
CREATE TABLE `tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `column_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `due_date` DATE NULL,
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    `position` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tasks_column` FOREIGN KEY (`column_id`) REFERENCES `columns` (`id`) ON DELETE CASCADE,
    INDEX `idx_tasks_column_pos` (`column_id`, `position`),
    INDEX `idx_tasks_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Task Assignments (Composite PK)
CREATE TABLE `task_assignments` (
    `task_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    PRIMARY KEY (`task_id`, `user_id`),
    CONSTRAINT `fk_ta_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ta_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_ta_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Comments Table
CREATE TABLE `comments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `comment_text` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_comments_task` (`task_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Notifications Table
CREATE TABLE `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `message` TEXT NOT NULL,
    `link` VARCHAR(255) NULL,
    `is_read` BOOLEAN NOT NULL DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_notifications_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================================
-- Seed Data for Initial Testing & Demo Experience
-- Password for all seed users is: password123
-- Hash generated via password_hash('password123', PASSWORD_DEFAULT)
-- ========================================================================

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `avatar_color`) VALUES
(1, 'alex_dev', 'alex@example.com', '$2y$10$ruwr.o1IiInWUmfcuc.4iuDMaHLTSjsejh1jZ59V6cZUBBEw.d8EW', '#6366f1'),
(2, 'sarah_pm', 'sarah@example.com', '$2y$10$ruwr.o1IiInWUmfcuc.4iuDMaHLTSjsejh1jZ59V6cZUBBEw.d8EW', '#ec4899'),
(3, 'marcus_lead', 'marcus@example.com', '$2y$10$ruwr.o1IiInWUmfcuc.4iuDMaHLTSjsejh1jZ59V6cZUBBEw.d8EW', '#10b981');

-- Seed Project
INSERT INTO `projects` (`id`, `name`, `description`, `owner_id`) VALUES
(1, 'Enterprise Cloud Migration', 'Cross-functional engineering initiative to migrate core microservices and legacy databases to high-availability multi-region Kubernetes clusters.', 1);

-- Project Members
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES
(1, 1, 'admin'),
(1, 2, 'member'),
(1, 3, 'member');

-- Columns
INSERT INTO `columns` (`id`, `project_id`, `name`, `position`) VALUES
(1, 1, 'Backlog', 0),
(2, 1, 'In Progress', 1),
(3, 1, 'Review & QA', 2),
(4, 1, 'Done', 3);

-- Tasks
INSERT INTO `tasks` (`id`, `column_id`, `title`, `description`, `due_date`, `priority`, `position`) VALUES
(1, 1, 'Architect multi-region VPC peering', 'Draft Terraform modules for high-throughput VPC peering with low-latency route tables across us-east-1 and eu-central-1.', DATE_ADD(CURRENT_DATE, INTERVAL 10 DAY), 'high', 0),
(2, 1, 'Evaluate Redis Cluster failover latency', 'Benchmark sub-millisecond Sentinel switchover times under simulated partition networks.', DATE_ADD(CURRENT_DATE, INTERVAL 14 DAY), 'medium', 1),
(3, 2, 'Deploy real-time SSE event pipeline', 'Configure persistent HTTP streaming endpoints in PHP with low buffer overhead and connection health pings.', DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY), 'urgent', 0),
(4, 2, 'Refactor OAuth2 token refresh flow', 'Ensure rotating refresh tokens with strict sliding expiration and revocation on anomaly detection.', DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY), 'high', 1),
(5, 3, 'Audit zero-trust IAM permission boundaries', 'Automate least-privilege scanning with AWS Access Analyzer and custom CI linting policies.', DATE_ADD(CURRENT_DATE, INTERVAL 2 DAY), 'medium', 0),
(6, 4, 'Containerize legacy authentication microservice', 'Dockerized Node/PHP microservice with multi-stage Alpine builds reducing image size by 78%.', DATE_SUB(CURRENT_DATE, INTERVAL 2 DAY), 'low', 0);

-- Task Assignments
INSERT INTO `task_assignments` (`task_id`, `user_id`) VALUES
(1, 1),
(1, 3),
(2, 3),
(3, 1),
(3, 2),
(4, 2),
(5, 3),
(6, 1);

-- Initial Comments
INSERT INTO `comments` (`task_id`, `user_id`, `comment_text`, `created_at`) VALUES
(3, 1, 'EventSource connection handlers and optimistic card movements are ready for validation.', NOW()),
(3, 2, 'Tested with concurrent clients. Memory consumption remains stable under 32MB.', NOW()),
(4, 2, 'Token revocation blacklists are stored in memory with fast Redis backup.', NOW());

-- Notifications
INSERT INTO `notifications` (`user_id`, `message`, `link`, `is_read`) VALUES
(1, 'Welcome to Enterprise Cloud Migration project board!', 'project.php?id=1', FALSE),
(1, 'Sarah commented on task: Deploy real-time SSE event pipeline', 'project.php?id=1&task=3', FALSE);
