<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['stock_keeper_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";


$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    $sql = "SELECT stock_keeper_id, name, email, password, status FROM stock_keeper_users WHERE email = '$email'";
    $result = $conn->query($sql);

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row['password']) && $row['status'] == 'active') {
            $_SESSION['stock_keeper_id'] = $row['stock_keeper_id'];
            $_SESSION['stock_keeper_name'] = $row['name'];
            $_SESSION['stock_keeper_email'] = $row['email'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error_message = "Invalid password or account is inactive.";
        }
    } else {
        $error_message = "Email not found.";
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Keeper Login - KIDS Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6a4c93;
            --primary-dark: #4a3570;
            --secondary: #8ac926;
            --accent: #28a745;
            --accent-dark: #219a41;
            --text-light: #ffffff;
            --text-dim: rgba(255, 255, 255, 0.8);
            --glass-bg: rgba(255, 255, 255, 0.12);
            --glass-border: rgba(255, 255, 255, 0.18);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
            --error-bg: rgba(220, 53, 69, 0.25);
            --error-border: rgba(220, 53, 69, 0.4);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 50%, var(--accent) 100%);
            background-size: 400% 400%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            animation: gradientShift 15s ease infinite;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Animated Background Elements */
        .bg-shapes {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1;
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            animation: float 25s infinite linear;
        }

        .shape:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }

        .shape:nth-child(2) {
            width: 120px;
            height: 120px;
            top: 70%;
            left: 80%;
            animation-delay: -5s;
        }

        .shape:nth-child(3) {
            width: 60px;
            height: 60px;
            top: 20%;
            left: 85%;
            animation-delay: -10s;
        }

        .shape:nth-child(4) {
            width: 100px;
            height: 100px;
            top: 80%;
            left: 15%;
            animation-delay: -15s;
        }

        .shape:nth-child(5) {
            width: 50px;
            height: 50px;
            top: 60%;
            left: 5%;
            animation-delay: -20s;
        }

        @keyframes float {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0.7;
            }
            50% {
                transform: translateY(-100px) rotate(180deg);
                opacity: 1;
            }
            100% {
                transform: translateY(0) rotate(360deg);
                opacity: 0.7;
            }
        }

        /* Main Login Container */
        .login-container {
            width: 90%;
            max-width: 420px;
            padding: 35px 30px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            z-index: 10;
            position: relative;
            animation: slideIn 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transition: transform 0.3s ease;
        }

        .login-container:hover {
            transform: translateY(-5px);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Logo Section */
        .logo-section {
            text-align: center;
            margin-bottom: 35px;
            position: relative;
        }

        .logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent), var(--secondary));
            border-radius: 50%;
            margin-bottom: 15px;
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.4);
            animation: pulse 2s infinite alternate;
            position: relative;
            overflow: hidden;
        }

        .logo-icon::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(to bottom right, rgba(255,255,255,0.3), rgba(255,255,255,0));
            transform: rotate(30deg);
        }

        .logo-icon i {
            font-size: 36px;
            color: white;
            z-index: 2;
            position: relative;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 8px 20px rgba(40, 167, 69, 0.4);
            }
            100% {
                box-shadow: 0 8px 30px rgba(40, 167, 69, 0.7);
            }
        }

        .logo-section h1 {
            color: var(--text-light);
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .logo-section p {
            color: var(--text-dim);
            font-size: 14px;
            font-weight: 400;
        }

        /* Form Elements */
        .form-group {
            position: relative;
            margin-bottom: 25px;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon input {
            width: 100%;
            padding: 16px 20px 16px 50px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            color: var(--text-light);
            font-size: 16px;
            font-weight: 400;
            outline: none;
            transition: all 0.3s ease;
        }

        .input-with-icon input:focus {
            background: rgba(255, 255, 255, 0.25);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.3);
        }

        .input-with-icon i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent);
            font-size: 18px;
            transition: all 0.3s ease;
        }

        .input-with-icon input:focus + i {
            color: var(--text-light);
            transform: translateY(-50%) scale(1.1);
        }

        .input-with-icon input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        /* Password Toggle */
        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--text-light);
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 17px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.4s ease;
            box-shadow: 0 6px 15px rgba(40, 167, 69, 0.4);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(40, 167, 69, 0.5);
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn i {
            font-size: 18px;
            transition: transform 0.3s ease;
        }

        .login-btn:hover i {
            transform: translateX(3px);
        }

        /* Error Message */
        .error-message {
            background: var(--error-bg);
            color: #ff8a8a;
            padding: 14px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid var(--error-border);
            animation: shake 0.5s ease;
            font-weight: 500;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-8px); }
            75% { transform: translateX(8px); }
        }

        /* Footer */
        .footer {
            text-align: center;
            margin-top: 30px;
            color: var(--text-dim);
            font-size: 14px;
            font-weight: 400;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                width: 95%;
                padding: 30px 20px;
            }
            
            .logo-icon {
                width: 70px;
                height: 70px;
            }
            
            .logo-icon i {
                font-size: 32px;
            }
            
            .logo-section h1 {
                font-size: 24px;
            }
            
            .input-with-icon input {
                padding: 14px 20px 14px 50px;
                font-size: 15px;
            }
            
            .login-btn {
                padding: 15px;
                font-size: 16px;
            }
        }

        /* Loading Animation */
        .loading {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Success Animation */
        @keyframes success {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>

    <!-- Animated Background Shapes -->
    <div class="bg-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <div class="login-container">
        <div class="logo-section">
            <div class="logo-icon">
                <i class="fas fa-warehouse"></i>
            </div>
            <h1>KIDS Berry</h1>
            <p>Stock Keeper Portal</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm">
            <div class="form-group">
                <div class="input-with-icon">
                    <input type="email" name="email" placeholder="Enter your email" required 
                           value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                    <i class="fas fa-envelope"></i>
                </div>
            </div>

            <div class="form-group">
                <div class="input-with-icon">
                    <input type="password" name="password" id="password" placeholder="Enter your password" required>
                    <i class="fas fa-lock"></i>
                    <span class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
            </div>

            <button type="submit" class="login-btn" id="submitBtn">
                <span id="btnText">Login to Stock Panel</span>
                <i class="fas fa-sign-in-alt"></i>
                <div class="loading" id="btnLoading"></div>
            </button>
        </form>

        <div class="footer">
            <p>© 2025 KIDS Berry • Secure Stock Management</p>
        </div>
    </div>

    <script>
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const passwordIcon = togglePassword.querySelector('i');
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            passwordIcon.classList.toggle('fa-eye');
            passwordIcon.classList.toggle('fa-eye-slash');
        });

        // Form submission animation
        const loginForm = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnLoading = document.getElementById('btnLoading');
        
        loginForm.addEventListener('submit', function() {
            btnText.style.display = 'none';
            btnLoading.style.display = 'block';
            submitBtn.disabled = true;
        });

        // Auto focus email field
        document.querySelector('input[name="email"]').focus();

        // Add floating animation to login container on load
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.login-container');
            container.style.transform = 'translateY(0)';
            
            // Add success animation if redirected from successful action
            if (window.location.search.includes('success')) {
                container.style.animation = 'success 0.5s ease';
            }
        });

        // Add interactive background effect on mouse move
        document.addEventListener('mousemove', function(e) {
            const shapes = document.querySelectorAll('.shape');
            const mouseX = e.clientX / window.innerWidth;
            const mouseY = e.clientY / window.innerHeight;
            
            shapes.forEach((shape, index) => {
                const speedX = (index + 1) * 0.5;
                const speedY = (index + 1) * 0.3;
                const x = (mouseX - 0.5) * speedX;
                const y = (mouseY - 0.5) * speedY;
                
                shape.style.transform = `translate(${x}px, ${y}px)`;
            });
        });
    </script>
</body>
</html>