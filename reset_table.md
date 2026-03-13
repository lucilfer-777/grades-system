SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM `notifications`;
DELETE FROM `audit_logs`;
DELETE FROM `grade_corrections`;
DELETE FROM `enrollment_requests`;
DELETE FROM `enrollments`;
DELETE FROM `grades`;

ALTER TABLE `notifications` AUTO_INCREMENT = 1;
ALTER TABLE `audit_logs` AUTO_INCREMENT = 1;
ALTER TABLE `grade_corrections` AUTO_INCREMENT = 1;
ALTER TABLE `enrollment_requests` AUTO_INCREMENT = 1;
ALTER TABLE `enrollments` AUTO_INCREMENT = 1;
ALTER TABLE `grades` AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;