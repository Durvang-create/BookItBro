<?php
declare(strict_types=1);

/*
 * BookMyShowDurvang - PDO backend
 * Authentication:
 * - password_hash() for registration
 * - password_verify() for login
 * - PHP sessions for session tracking
 * - role-based protection for admin endpoints
 */

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

header('Content-Type: application/json; charset=utf-8');

$host = 'localhost';
$db   = 'bookmyshowdurvang';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Start MySQL in XAMPP and check the database name.'
    ]);
    exit;
}

function response(bool $success, string $message = '', $data = null, int $status = 200): never
{
    http_response_code($status);

    $out = [
        'success' => $success,
        'message' => $message
    ];

    if ($data !== null) {
        $out['data'] = $data;
    }

    echo json_encode($out);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST ?: [];
}

function requireLogin(): array
{
    if (empty($_SESSION['user'])) {
        response(false, 'Please login first.', null, 401);
    }

    return $_SESSION['user'];
}

function requireAdmin(): array
{
    $user = requireLogin();

    if (($user['role'] ?? '') !== 'admin') {
        response(false, 'Admin access required.', null, 403);
    }

    return $user;
}

function cleanString($value): string
{
    return trim((string)($value ?? ''));
}

$action = $_GET['action'] ?? '';

// ---------------- AUTHENTICATION ----------------

if ($action === 'register') {
    $data = body();

    $name     = cleanString($data['name'] ?? '');
    $email    = strtolower(cleanString($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if (strlen($name) < 2) {
        response(false, 'Please enter a valid name.', null, 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, 'Please enter a valid email address.', null, 422);
    }

    if (strlen($password) < 6) {
        response(false, 'Password must contain at least 6 characters.', null, 422);
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);

    if ($check->fetch()) {
        response(false, 'An account with this email already exists.', null, 409);
    }

    // Never store the plain-text password.
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, password, role)
         VALUES (?, ?, ?, ?)'
    );

    $stmt->execute([$name, $email, $hashedPassword, 'user']);

    response(true, 'Account created successfully. You can now login.');
}

if ($action === 'login') {
    $data = body();

    $email    = strtolower(cleanString($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        response(false, 'Please enter a valid email and password.', null, 422);
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, email, password, role
         FROM users
         WHERE email = ?
         LIMIT 1'
    );

    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password'])) {
        response(false, 'Invalid email or password.', null, 401);
    }

    // Prevent session fixation after successful authentication.
    session_regenerate_id(true);

    unset($row['password']);

    $_SESSION['user'] = [
        'id'    => (int)$row['id'],
        'name'  => $row['name'],
        'email' => $row['email'],
        'role'  => $row['role']
    ];

    response(true, 'Login successful.', $_SESSION['user']);
}

if ($action === 'logout') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();

    response(true, 'You have been logged out.');
}

if ($action === 'me') {
    if (empty($_SESSION['user'])) {
        response(false, 'Not logged in.', null, 401);
    }

    response(true, 'Session active.', $_SESSION['user']);
}

// ---------------- MOVIES ----------------

if ($action === 'movies') {
    $stmt = $pdo->query(
        'SELECT id, title, genre, language, duration, price, poster, rating
         FROM movies
         ORDER BY id DESC'
    );

    response(true, 'Movies loaded.', $stmt->fetchAll());
}

if ($action === 'movie_add') {
    requireAdmin();

    $data = body();

    $title    = cleanString($data['title'] ?? '');
    $genre    = cleanString($data['genre'] ?? '');
    $language = cleanString($data['language'] ?? '');
    $duration = (int)($data['duration'] ?? 0);
    $price    = (float)($data['price'] ?? 0);
    $rating   = (float)($data['rating'] ?? 0);
    $poster   = cleanString($data['poster'] ?? '');

    if ($title === '' || $genre === '' || $language === '' || $duration <= 0 || $price <= 0) {
        response(false, 'Please fill all required movie fields correctly.', null, 422);
    }

    if ($rating < 0 || $rating > 10) {
        response(false, 'Rating must be between 0 and 10.', null, 422);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO movies
         (title, genre, language, duration, price, poster, rating)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $title,
        $genre,
        $language,
        $duration,
        $price,
        $poster,
        $rating
    ]);

    response(true, 'Movie added successfully.', ['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'movie_update') {
    requireAdmin();

    $data = body();

    $id       = (int)($data['id'] ?? 0);
    $title    = cleanString($data['title'] ?? '');
    $genre    = cleanString($data['genre'] ?? '');
    $language = cleanString($data['language'] ?? '');
    $duration = (int)($data['duration'] ?? 0);
    $price    = (float)($data['price'] ?? 0);
    $rating   = (float)($data['rating'] ?? 0);
    $poster   = cleanString($data['poster'] ?? '');

    if ($id <= 0 || $title === '' || $genre === '' || $language === '' || $duration <= 0 || $price <= 0) {
        response(false, 'Please fill all required movie fields correctly.', null, 422);
    }

    if ($rating < 0 || $rating > 10) {
        response(false, 'Rating must be between 0 and 10.', null, 422);
    }

    $stmt = $pdo->prepare(
        'UPDATE movies
         SET title = ?, genre = ?, language = ?, duration = ?, price = ?, poster = ?, rating = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $title,
        $genre,
        $language,
        $duration,
        $price,
        $poster,
        $rating,
        $id
    ]);

    response(true, 'Movie updated successfully.');
}

if ($action === 'movie_delete') {
    requireAdmin();

    $data = body();
    $id = (int)($data['id'] ?? 0);

    if ($id <= 0) {
        response(false, 'Invalid movie ID.', null, 422);
    }

    $stmt = $pdo->prepare('DELETE FROM movies WHERE id = ?');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        response(false, 'Movie not found.', null, 404);
    }

    response(true, 'Movie removed successfully.');
}

// ---------------- BOOKINGS ----------------

if ($action === 'bookings') {
    $user = requireLogin();

    if ($user['role'] === 'admin') {
        $stmt = $pdo->query(
            'SELECT
                b.id,
                b.user_id,
                b.movie_id,
                u.name AS customer,
                m.title AS movie,
                b.show_date AS date,
                b.show_time AS time,
                b.seats,
                b.total,
                b.status
             FROM bookings b
             INNER JOIN users u ON u.id = b.user_id
             INNER JOIN movies m ON m.id = b.movie_id
             ORDER BY b.id DESC'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT
                b.id,
                b.user_id,
                b.movie_id,
                m.title AS movie,
                b.show_date AS date,
                b.show_time AS time,
                b.seats,
                b.total,
                b.status
             FROM bookings b
             INNER JOIN movies m ON m.id = b.movie_id
             WHERE b.user_id = ?
             ORDER BY b.id DESC'
        );

        $stmt->execute([(int)$user['id']]);
    }

    response(true, 'Bookings loaded.', $stmt->fetchAll());
}

if ($action === 'booking_add') {
    $user = requireLogin();
    $data = body();

    $movieId = (int)($data['movie_id'] ?? 0);
    $date    = cleanString($data['date'] ?? '');
    $time    = cleanString($data['time'] ?? '');
    $seats   = cleanString($data['seats'] ?? '');

    if ($movieId <= 0 || $date === '' || $time === '' || $seats === '') {
        response(false, 'Please select movie, date, showtime and seats.', null, 422);
    }

    $dateObject = DateTime::createFromFormat('Y-m-d', $date);

    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        response(false, 'Invalid booking date.', null, 422);
    }

    if ($date < date('Y-m-d')) {
        response(false, 'Booking date cannot be in the past.', null, 422);
    }

    $movieStmt = $pdo->prepare(
        'SELECT id, price FROM movies WHERE id = ? LIMIT 1'
    );
    $movieStmt->execute([$movieId]);
    $movie = $movieStmt->fetch();

    if (!$movie) {
        response(false, 'Movie not found.', null, 404);
    }

    $seatList = array_values(array_filter(
        array_map('trim', explode(',', $seats))
    ));

    if (count($seatList) < 1) {
        response(false, 'Please select at least one seat.', null, 422);
    }

    if (count($seatList) > 40) {
        response(false, 'Too many seats selected.', null, 422);
    }

    $seatList = array_values(array_unique($seatList));
    $seatString = implode(', ', $seatList);
    $total = count($seatList) * (float)$movie['price'];

    $stmt = $pdo->prepare(
        'INSERT INTO bookings
         (user_id, movie_id, show_date, show_time, seats, total, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        (int)$user['id'],
        $movieId,
        $date,
        $time,
        $seatString,
        $total,
        'Confirmed'
    ]);

    response(true, 'Booking confirmed successfully.', [
        'id' => (int)$pdo->lastInsertId(),
        'total' => $total
    ]);
}

if ($action === 'booking_update') {
    $user = requireLogin();
    $data = body();

    $id      = (int)($data['id'] ?? 0);
    $movieId = (int)($data['movie_id'] ?? 0);
    $date    = cleanString($data['date'] ?? '');
    $time    = cleanString($data['time'] ?? '');
    $seats   = cleanString($data['seats'] ?? '');

    if ($id <= 0 || $movieId <= 0 || $date === '' || $time === '' || $seats === '') {
        response(false, 'Please provide all booking details.', null, 422);
    }

    if ($date < date('Y-m-d')) {
        response(false, 'Booking date cannot be in the past.', null, 422);
    }

    $seatList = array_values(array_unique(array_filter(
        array_map('trim', explode(',', $seats))
    )));

    if (!$seatList) {
        response(false, 'Please select at least one seat.', null, 422);
    }

    $movieStmt = $pdo->prepare('SELECT price FROM movies WHERE id = ? LIMIT 1');
    $movieStmt->execute([$movieId]);
    $movie = $movieStmt->fetch();

    if (!$movie) {
        response(false, 'Movie not found.', null, 404);
    }

    // Users can update only their own bookings; admins can update any booking.
    if ($user['role'] === 'admin') {
        $bookingStmt = $pdo->prepare('SELECT id FROM bookings WHERE id = ? LIMIT 1');
        $bookingStmt->execute([$id]);
    } else {
        $bookingStmt = $pdo->prepare(
            'SELECT id FROM bookings WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $bookingStmt->execute([$id, (int)$user['id']]);
    }

    if (!$bookingStmt->fetch()) {
        response(false, 'Booking not found or access denied.', null, 404);
    }

    $seatString = implode(', ', $seatList);
    $total = count($seatList) * (float)$movie['price'];

    $stmt = $pdo->prepare(
        'UPDATE bookings
         SET movie_id = ?, show_date = ?, show_time = ?, seats = ?, total = ?, status = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $movieId,
        $date,
        $time,
        $seatString,
        $total,
        'Confirmed',
        $id
    ]);

    response(true, 'Booking updated successfully.');
}

if ($action === 'booking_delete') {
    $user = requireLogin();
    $data = body();

    $id = (int)($data['id'] ?? 0);

    if ($id <= 0) {
        response(false, 'Invalid booking ID.', null, 422);
    }

    if ($user['role'] === 'admin') {
        $stmt = $pdo->prepare('DELETE FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare(
            'DELETE FROM bookings WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, (int)$user['id']]);
    }

    if ($stmt->rowCount() === 0) {
        response(false, 'Booking not found or access denied.', null, 404);
    }

    response(true, 'Booking cancelled successfully.');
}

response(false, 'Invalid API action.', null, 404);
?>
