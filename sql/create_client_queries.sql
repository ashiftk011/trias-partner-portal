-- Database migration: Add Client Queries & Suggestions table

USE trias_portal;

CREATE TABLE IF NOT EXISTS client_queries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    type ENUM('query', 'suggestion') NOT NULL DEFAULT 'query',
    description TEXT NOT NULL,
    status ENUM('pending', 'blocked', 'resolved', 'closed') NOT NULL DEFAULT 'pending',
    task_link VARCHAR(500) NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
