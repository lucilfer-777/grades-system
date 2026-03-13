-- Migration: Add assessment components for academically accurate grade encoding

-- Table for defining assessment components and their weights per subject
CREATE TABLE IF NOT EXISTS assessment_components (
  component_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL,
  component_name VARCHAR(50) NOT NULL,
  weight DECIMAL(5,2) NOT NULL, -- e.g., 20.00 for 20%
  CONSTRAINT fk_ac_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id)
);

-- Table for storing student scores per component
CREATE TABLE IF NOT EXISTS grade_components (
  grade_component_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  academic_period VARCHAR(50) NOT NULL,
  component_id INT NOT NULL,
  raw_score DECIMAL(6,2) NOT NULL,
  max_score DECIMAL(6,2) NOT NULL,
  CONSTRAINT fk_gc_student FOREIGN KEY (student_id) REFERENCES users(user_id),
  CONSTRAINT fk_gc_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id),
  CONSTRAINT fk_gc_component FOREIGN KEY (component_id) REFERENCES assessment_components(component_id)
);

-- You may want to pre-populate assessment_components for each subject with typical components (e.g., Quiz, Exam, Assignment, Project) and their weights.
