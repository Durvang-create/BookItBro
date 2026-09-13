<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'bookmyshowdurvang';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database connection failed. Create the database using database.sql.']);
    exit;
}
$conn->set_charset('utf8mb4');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function response($success, $message = '', $data = []) {
    echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data]);
    exit;
}
function body() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}
function requireLogin() {
    if (empty($_SESSION['user'])) response(false, 'Please login first.');
    return $_SESSION['user'];
}
function requireAdmin() {
    $u = requireLogin();
    if (($u['role'] ?? '') !== 'admin') response(false, 'Admin access required.');
    return $u;
}

if ($action === 'register' && $method === 'POST') {
    $d = body();
    $name = trim($d['name'] ?? '');
    $email = strtolower(trim($d['email'] ?? ''));
    $password = $d['password'] ?? '';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) response(false, 'Please enter valid registration details.');
    $stmt = $conn->prepare('SELECT id FROM users WHERE email=?');
    $stmt->bind_param('s', $email); $stmt->execute();
    if ($stmt->get_result()->num_rows) response(false, 'An account with this email already exists.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,"user")');
    $stmt->bind_param('sss', $name, $email, $hash);
    if (!$stmt->execute()) response(false, 'Registration failed.');
    response(true, 'Account created successfully.');
}

if ($action === 'login' && $method === 'POST') {
    $d = body();
    $email = strtolower(trim($d['email'] ?? ''));
    $password = $d['password'] ?? '';
    $stmt = $conn->prepare('SELECT id,name,email,password,role FROM users WHERE email=? LIMIT 1');
    $stmt->bind_param('s', $email); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !password_verify($password, $row['password'])) response(false, 'Invalid email or password.');
    unset($row['password']);
    $_SESSION['user'] = $row;
    response(true, 'Login successful.', $row);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    response(true, 'Logged out.');
}

if ($action === 'me') {
    response(true, '', $_SESSION['user'] ?? null);
}

if ($action === 'movies' && $method === 'GET') {
    $result = $conn->query('SELECT id,title,genre,language,duration,price,poster,rating FROM movies ORDER BY id DESC');
    response(true, '', $result->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'movie_add' && $method === 'POST') {
    requireAdmin(); $d=body();
    $title=trim($d['title']??''); $genre=trim($d['genre']??''); $language=trim($d['language']??'');
    $duration=(int)($d['duration']??0); $price=(float)($d['price']??0); $poster=trim($d['poster']??''); $rating=(float)($d['rating']??0);
    if (!$title || !$genre || !$language || $duration<=0 || $price<=0) response(false,'Please fill all movie details correctly.');
    $stmt=$conn->prepare('INSERT INTO movies(title,genre,language,duration,price,poster,rating) VALUES(?,?,?,?,?,?,?)');
    $stmt->bind_param('sssidsd',$title,$genre,$language,$duration,$price,$poster,$rating);
    response($stmt->execute(),'Movie added successfully.');
}

if ($action === 'movie_update' && $method === 'POST') {
    requireAdmin(); $d=body(); $id=(int)($d['id']??0);
    $title=trim($d['title']??''); $genre=trim($d['genre']??''); $language=trim($d['language']??'');
    $duration=(int)($d['duration']??0); $price=(float)($d['price']??0); $poster=trim($d['poster']??''); $rating=(float)($d['rating']??0);
    $stmt=$conn->prepare('UPDATE movies SET title=?,genre=?,language=?,duration=?,price=?,poster=?,rating=? WHERE id=?');
    $stmt->bind_param('sssidsdi',$title,$genre,$language,$duration,$price,$poster,$rating,$id);
    response($stmt->execute(),'Movie updated successfully.');
}

if ($action === 'movie_delete' && $method === 'POST') {
    requireAdmin(); $d=body(); $id=(int)($d['id']??0);
    $stmt=$conn->prepare('DELETE FROM movies WHERE id=?'); $stmt->bind_param('i',$id);
    response($stmt->execute(),'Movie removed successfully.');
}

if ($action === 'bookings' && $method === 'GET') {
    $u=requireLogin();
    if (($u['role']??'') === 'admin') {
        $sql='SELECT b.id,b.user_id,b.movie_id,m.title movie,b.show_date date,b.show_time time,b.seats,b.total,b.status,u.name customer,u.email FROM bookings b JOIN movies m ON m.id=b.movie_id JOIN users u ON u.id=b.user_id ORDER BY b.id DESC';
        $result=$conn->query($sql);
    } else {
        $stmt=$conn->prepare('SELECT b.id,b.movie_id,m.title movie,b.show_date date,b.show_time time,b.seats,b.total,b.status FROM bookings b JOIN movies m ON m.id=b.movie_id WHERE b.user_id=? ORDER BY b.id DESC');
        $stmt->bind_param('i',$u['id']); $stmt->execute(); $result=$stmt->get_result();
    }
    response(true,'',$result->fetch_all(MYSQLI_ASSOC));
}

if ($action === 'booking_add' && $method === 'POST') {
    $u=requireLogin(); $d=body();
    $movie_id=(int)($d['movie_id']??0); $date=$d['date']??''; $time=$d['time']??''; $seats=trim($d['seats']??'');
    $seatList=array_values(array_filter(array_map('trim',explode(',',$seats))));
    if (!$movie_id || !$date || !$time || !$seatList) response(false,'Please select a movie, date, time and at least one seat.');
    $stmt=$conn->prepare('SELECT price FROM movies WHERE id=?'); $stmt->bind_param('i',$movie_id); $stmt->execute(); $movie=$stmt->get_result()->fetch_assoc();
    if (!$movie) response(false,'Movie not found.');
    $total=count($seatList)*(float)$movie['price'];
    $seatString=implode(', ',$seatList);
    $stmt=$conn->prepare('INSERT INTO bookings(user_id,movie_id,show_date,show_time,seats,total,status) VALUES(?,?,?,?,?,?,"Confirmed")');
    $stmt->bind_param('iisssd',$u['id'],$movie_id,$date,$time,$seatString,$total);
    response($stmt->execute(),'Booking confirmed.',['id'=>$conn->insert_id]);
}

if ($action === 'booking_update' && $method === 'POST') {
    $u=requireLogin(); $d=body(); $id=(int)($d['id']??0); $movie_id=(int)($d['movie_id']??0);
    $date=$d['date']??''; $time=$d['time']??''; $seats=trim($d['seats']??'');
    $seatList=array_values(array_filter(array_map('trim',explode(',',$seats))));
    if (!$id || !$movie_id || !$date || !$time || !$seatList) response(false,'Please complete the booking details.');
    $check=$conn->prepare('SELECT price FROM movies WHERE id=?'); $check->bind_param('i',$movie_id); $check->execute(); $movie=$check->get_result()->fetch_assoc();
    if (!$movie) response(false,'Movie not found.');
    $check=$conn->prepare('SELECT id FROM bookings WHERE id=? AND user_id=?'); $check->bind_param('ii',$id,$u['id']); $check->execute();
    if (!$check->get_result()->num_rows && ($u['role']??'')!=='admin') response(false,'You can only change your own booking.');
    $total=count($seatList)*(float)$movie['price']; $seatString=implode(', ',$seatList);
    $stmt=$conn->prepare('UPDATE bookings SET movie_id=?,show_date=?,show_time=?,seats=?,total=? WHERE id=?');
    $stmt->bind_param('isssdi',$movie_id,$date,$time,$seatString,$total,$id);
    response($stmt->execute(),'Booking updated successfully.');
}

if ($action === 'booking_delete' && $method === 'POST') {
    $u=requireLogin(); $d=body(); $id=(int)($d['id']??0);
    if (($u['role']??'')==='admin') { $stmt=$conn->prepare('DELETE FROM bookings WHERE id=?'); $stmt->bind_param('i',$id); }
    else { $stmt=$conn->prepare('DELETE FROM bookings WHERE id=? AND user_id=?'); $stmt->bind_param('ii',$id,$u['id']); }
    response($stmt->execute(),'Booking cancelled successfully.');
}

response(false,'Invalid request.');
?>
