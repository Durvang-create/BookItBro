<?php
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'bookmyshowdurvang';

try {
    // PDO database connection
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

    $conn = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Create the database using database.sql.'
    ]);

    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];


/* =========================
   HELPER FUNCTIONS
========================= */

function response($success, $message = '', $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);

    exit;
}


function body()
{
    $raw = file_get_contents('php://input');

    $json = json_decode($raw, true);

    return is_array($json) ? $json : $_POST;
}


function requireLogin()
{
    if (empty($_SESSION['user'])) {
        response(false, 'Please login first.');
    }

    return $_SESSION['user'];
}


function requireAdmin()
{
    $u = requireLogin();

    if (($u['role'] ?? '') !== 'admin') {
        response(false, 'Admin access required.');
    }

    return $u;
}


/* =========================
   REGISTER
========================= */

if ($action === 'register' && $method === 'POST') {

    $d = body();

    $name = trim($d['name'] ?? '');
    $email = strtolower(trim($d['email'] ?? ''));
    $password = $d['password'] ?? '';

    if (
        $name === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($password) < 6
    ) {
        response(false, 'Please enter valid registration details.');
    }

    // Check existing user
    $stmt = $conn->prepare(
        'SELECT id FROM users WHERE email = ?'
    );

    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        response(false, 'An account with this email already exists.');
    }

    // Hash password
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Insert user
    $stmt = $conn->prepare(
        'INSERT INTO users (name, email, password, role)
         VALUES (?, ?, ?, "user")'
    );

    $success = $stmt->execute([
        $name,
        $email,
        $hash
    ]);

    if (!$success) {
        response(false, 'Registration failed.');
    }

    response(true, 'Account created successfully.');
}


/* =========================
   LOGIN
========================= */

if ($action === 'login' && $method === 'POST') {

    $d = body();

    $email = strtolower(trim($d['email'] ?? ''));
    $password = $d['password'] ?? '';

    $stmt = $conn->prepare(
        'SELECT id, name, email, password, role
         FROM users
         WHERE email = ?
         LIMIT 1'
    );

    $stmt->execute([$email]);

    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password'])) {
        response(false, 'Invalid email or password.');
    }

    // Remove password before storing user in session
    unset($row['password']);

    $_SESSION['user'] = $row;

    response(true, 'Login successful.', $row);
}


/* =========================
   LOGOUT
========================= */

if ($action === 'logout') {

    $_SESSION = [];

    session_destroy();

    response(true, 'Logged out.');
}


/* =========================
   CURRENT USER
========================= */

if ($action === 'me') {

    response(
        true,
        '',
        $_SESSION['user'] ?? null
    );
}


/* =========================
   GET MOVIES
========================= */

if ($action === 'movies' && $method === 'GET') {

    $stmt = $conn->prepare(
        'SELECT id, title, genre, language, duration,
                price, poster, rating
         FROM movies
         ORDER BY id DESC'
    );

    $stmt->execute();

    $movies = $stmt->fetchAll();

    response(true, '', $movies);
}


/* =========================
   ADD MOVIE - ADMIN
========================= */

if ($action === 'movie_add' && $method === 'POST') {

    requireAdmin();

    $d = body();

    $title = trim($d['title'] ?? '');
    $genre = trim($d['genre'] ?? '');
    $language = trim($d['language'] ?? '');
    $duration = (int)($d['duration'] ?? 0);
    $price = (float)($d['price'] ?? 0);
    $poster = trim($d['poster'] ?? '');
    $rating = (float)($d['rating'] ?? 0);

    if (
        !$title ||
        !$genre ||
        !$language ||
        $duration <= 0 ||
        $price <= 0
    ) {
        response(
            false,
            'Please fill all movie details correctly.'
        );
    }

    $stmt = $conn->prepare(
        'INSERT INTO movies
        (title, genre, language, duration, price, poster, rating)
        VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $success = $stmt->execute([
        $title,
        $genre,
        $language,
        $duration,
        $price,
        $poster,
        $rating
    ]);

    response(
        $success,
        'Movie added successfully.'
    );
}


/* =========================
   UPDATE MOVIE - ADMIN
========================= */

if ($action === 'movie_update' && $method === 'POST') {

    requireAdmin();

    $d = body();

    $id = (int)($d['id'] ?? 0);
    $title = trim($d['title'] ?? '');
    $genre = trim($d['genre'] ?? '');
    $language = trim($d['language'] ?? '');
    $duration = (int)($d['duration'] ?? 0);
    $price = (float)($d['price'] ?? 0);
    $poster = trim($d['poster'] ?? '');
    $rating = (float)($d['rating'] ?? 0);

    $stmt = $conn->prepare(
        'UPDATE movies
         SET title = ?,
             genre = ?,
             language = ?,
             duration = ?,
             price = ?,
             poster = ?,
             rating = ?
         WHERE id = ?'
    );

    $success = $stmt->execute([
        $title,
        $genre,
        $language,
        $duration,
        $price,
        $poster,
        $rating,
        $id
    ]);

    response(
        $success,
        'Movie updated successfully.'
    );
}


/* =========================
   DELETE MOVIE - ADMIN
========================= */

if ($action === 'movie_delete' && $method === 'POST') {

    requireAdmin();

    $d = body();

    $id = (int)($d['id'] ?? 0);

    $stmt = $conn->prepare(
        'DELETE FROM movies WHERE id = ?'
    );

    $success = $stmt->execute([$id]);

    response(
        $success,
        'Movie removed successfully.'
    );
}


/* =========================
   GET BOOKINGS
========================= */

if ($action === 'bookings' && $method === 'GET') {

    $u = requireLogin();

    if (($u['role'] ?? '') === 'admin') {

        $sql = '
            SELECT
                b.id,
                b.user_id,
                b.movie_id,
                m.title AS movie,
                b.show_date AS date,
                b.show_time AS time,
                b.seats,
                b.total,
                b.status,
                u.name AS customer,
                u.email
            FROM bookings b
            JOIN movies m ON m.id = b.movie_id
            JOIN users u ON u.id = b.user_id
            ORDER BY b.id DESC
        ';

        $stmt = $conn->prepare($sql);

        $stmt->execute();

        $bookings = $stmt->fetchAll();

    } else {

        $stmt = $conn->prepare(
            'SELECT
                b.id,
                b.movie_id,
                m.title AS movie,
                b.show_date AS date,
                b.show_time AS time,
                b.seats,
                b.total,
                b.status
             FROM bookings b
             JOIN movies m ON m.id = b.movie_id
             WHERE b.user_id = ?
             ORDER BY b.id DESC'
        );

        $stmt->execute([$u['id']]);

        $bookings = $stmt->fetchAll();
    }

    response(true, '', $bookings);
}


/* =========================
   ADD BOOKING
========================= */

if ($action === 'booking_add' && $method === 'POST') {

    $u = requireLogin();

    $d = body();

    $movie_id = (int)($d['movie_id'] ?? 0);
    $date = $d['date'] ?? '';
    $time = $d['time'] ?? '';
    $seats = trim($d['seats'] ?? '');

    $seatList = array_values(
        array_filter(
            array_map('trim', explode(',', $seats))
        )
    );

    if (
        !$movie_id ||
        !$date ||
        !$time ||
        !$seatList
    ) {
        response(
            false,
            'Please select a movie, date, time and at least one seat.'
        );
    }

    // Get movie price
    $stmt = $conn->prepare(
        'SELECT price FROM movies WHERE id = ?'
    );

    $stmt->execute([$movie_id]);

    $movie = $stmt->fetch();

    if (!$movie) {
        response(false, 'Movie not found.');
    }

    $total = count($seatList) * (float)$movie['price'];

    $seatString = implode(', ', $seatList);

    $stmt = $conn->prepare(
        'INSERT INTO bookings
        (user_id, movie_id, show_date, show_time, seats, total, status)
        VALUES (?, ?, ?, ?, ?, ?, "Confirmed")'
    );

    $success = $stmt->execute([
        $u['id'],
        $movie_id,
        $date,
        $time,
        $seatString,
        $total
    ]);

    response(
        $success,
        'Booking confirmed.',
        [
            'id' => $conn->lastInsertId()
        ]
    );
}


/* =========================
   UPDATE BOOKING
========================= */

if ($action === 'booking_update' && $method === 'POST') {

    $u = requireLogin();

    $d = body();

    $id = (int)($d['id'] ?? 0);
    $movie_id = (int)($d['movie_id'] ?? 0);
    $date = $d['date'] ?? '';
    $time = $d['time'] ?? '';
    $seats = trim($d['seats'] ?? '');

    $seatList = array_values(
        array_filter(
            array_map('trim', explode(',', $seats))
        )
    );

    if (
        !$id ||
        !$movie_id ||
        !$date ||
        !$time ||
        !$seatList
    ) {
        response(
            false,
            'Please complete the booking details.'
        );
    }

    // Get movie price
    $check = $conn->prepare(
        'SELECT price FROM movies WHERE id = ?'
    );

    $check->execute([$movie_id]);

    $movie = $check->fetch();

    if (!$movie) {
        response(false, 'Movie not found.');
    }

    // Check booking ownership
    $check = $conn->prepare(
        'SELECT id
         FROM bookings
         WHERE id = ?
         AND user_id = ?'
    );

    $check->execute([
        $id,
        $u['id']
    ]);

    $bookingExists = $check->fetch();

    if (
        !$bookingExists &&
        ($u['role'] ?? '') !== 'admin'
    ) {
        response(
            false,
            'You can only change your own booking.'
        );
    }

    $total = count($seatList) * (float)$movie['price'];

    $seatString = implode(', ', $seatList);

    $stmt = $conn->prepare(
        'UPDATE bookings
         SET movie_id = ?,
             show_date = ?,
             show_time = ?,
             seats = ?,
             total = ?
         WHERE id = ?'
    );

    $success = $stmt->execute([
        $movie_id,
        $date,
        $time,
        $seatString,
        $total,
        $id
    ]);

    response(
        $success,
        'Booking updated successfully.'
    );
}


/* =========================
   DELETE BOOKING
========================= */

if ($action === 'booking_delete' && $method === 'POST') {

    $u = requireLogin();

    $d = body();

    $id = (int)($d['id'] ?? 0);

    if (($u['role'] ?? '') === 'admin') {

        $stmt = $conn->prepare(
            'DELETE FROM bookings WHERE id = ?'
        );

        $stmt->execute([$id]);

    } else {

        $stmt = $conn->prepare(
            'DELETE FROM bookings
             WHERE id = ?
             AND user_id = ?'
        );

        $stmt->execute([
            $id,
            $u['id']
        ]);
    }

    response(
        true,
        'Booking cancelled successfully.'
    );
}


/* =========================
   INVALID REQUEST
========================= */

response(false, 'Invalid request.');

?>
