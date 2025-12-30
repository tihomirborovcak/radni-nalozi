-- Tablica za opće taskove/podsjetnike
CREATE TABLE IF NOT EXISTS taskovi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tekst TEXT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    zavrsen TINYINT(1) DEFAULT 0,
    zavrsen_by INT NULL,
    zavrsen_at DATETIME NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;
