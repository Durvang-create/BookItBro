CREATE DATABASE IF NOT EXISTS bookmyshowdurvang CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bookmyshowdurvang;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS movies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  genre VARCHAR(80) NOT NULL,
  language VARCHAR(50) NOT NULL,
  duration INT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  poster TEXT,
  rating DECIMAL(3,1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  movie_id INT NOT NULL,
  show_date DATE NOT NULL,
  show_time VARCHAR(30) NOT NULL,
  seats VARCHAR(255) NOT NULL,
  total DECIMAL(10,2) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Confirmed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
);

INSERT INTO users (name,email,password,role)
VALUES ('Administrator','admin@bookmyshowdurvang.com','$2y$12$U6/DUx7r5B3x3b3sReaXtuTWIbjDfjlebxF3CmoMvicYP4TM02PdG','admin')
ON DUPLICATE KEY UPDATE email=email;

INSERT INTO movies (title,genre,language,duration,price,poster,rating)
SELECT 'Interstellar','Sci-Fi','English',169,250,'',8.7 WHERE NOT EXISTS (SELECT 1 FROM movies WHERE title='Interstellar');
INSERT INTO movies (title,genre,language,duration,price,poster,rating)
SELECT 'Inception','Thriller','English',148,250,'',8.8 WHERE NOT EXISTS (SELECT 1 FROM movies WHERE title='Inception');
INSERT INTO movies (title,genre,language,duration,price,poster,rating)
SELECT 'Avengers: Endgame','Action','English',181,250,'',8.4 WHERE NOT EXISTS (SELECT 1 FROM movies WHERE title='Avengers: Endgame');
INSERT INTO movies (title,genre,language,duration,price,poster,rating)
SELECT 'Dune: Part Two','Sci-Fi','English',166,250,'',8.5 WHERE NOT EXISTS (SELECT 1 FROM movies WHERE title='Dune: Part Two');
