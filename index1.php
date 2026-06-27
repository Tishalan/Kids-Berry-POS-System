<?php
session_start();

// // Redirect if already logged in
// if (isset($_SESSION['cashier_id'])) {
//     header("Location: billing.php");
//     exit();
// }

// if (isset($_SESSION['stock_keeper_id'])) {
//     header("Location: dashboard.php");
//     exit();
// }

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
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $user_type = $_POST['user_type'];
    
    if ($user_type == 'cashier') {
        $sql = "SELECT cashiers_id, name, email, password, status FROM cashier WHERE email = '$email'";
        $result = $conn->query($sql);

        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password']) && $row['status'] == 'active') {
                $_SESSION['cashiers_id'] = $row['cashiers_id'];
                $_SESSION['cashier_name'] = $row['name'];
                $_SESSION['cashier_email'] = $row['email'];
                header("Location: cashier/billing.php");
                exit();
            } else {
                $error_message = "Invalid email, password, or account is inactive.";
            }
        } else {
            $error_message = "Invalid email or password.";
        }
    } 
    elseif ($user_type == 'stock_keeper') {
        $sql = "SELECT stock_keeper_id, name, email, password, status FROM stock_keeper_users WHERE email = '$email'";
        $result = $conn->query($sql);

        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password']) && $row['status'] == 'active') {
                $_SESSION['stock_keeper_id'] = $row['stock_keeper_id'];
                $_SESSION['stock_keeper_name'] = $row['name'];
                $_SESSION['stock_keeper_email'] = $row['email'];
                header("Location: stock_keeper/dashboard.php");
                exit();
            } else {
                $error_message = "Invalid password or account is inactive.";
            }
        } else {
            $error_message = "Email not found.";
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - KIDS Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #5a3d7e; /* Purple */
            --secondary: #28a745; /* Light Green */
            --accent: #dc3545;
            --success: #28a745;
            --light-bg: #f8f9fa;
            --white: #ffffff;
            --shadow: 0 4px 15px rgba(90, 61, 126, 0.2);
            --shadow-hover: 0 8px 25px rgba(90, 61, 126, 0.3);
            --gradient: linear-gradient(135deg, var(--primary) 0%, #7b68ee 50%, var(--secondary) 100%);
            --cashier-color: #5a3d7e;
            --stock-color: #28a745;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            padding: 15px;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 10% 20%, rgba(255,255,255,0.1) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(255,255,255,0.1) 0%, transparent 20%),
                radial-gradient(circle at 50% 50%, rgba(255,255,255,0.05) 0%, transparent 20%);
            animation: float 15s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { 
                transform: translateY(0px) scale(1);
                opacity: 0.7;
            }
            50% { 
                transform: translateY(-20px) scale(1.05);
                opacity: 1;
            }
        }

        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: 
                var(--shadow),
                0 10px 30px rgba(0, 0, 0, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.6);
            width: 100%;
            max-width: 450px;
            text-align: center;
            position: relative;
            z-index: 1;
            animation: slideInUp 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .login-header {
            margin-bottom: 2rem;
            position: relative;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: var(--gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: var(--shadow);
            animation: bounce 2s infinite alternate;
        }

        @keyframes bounce {
            from { transform: translateY(0px); }
            to { transform: translateY(-10px); }
        }

        .logo i {
            font-size: 2.5rem;
            color: white;
        }

        .login-header h1 {
            color: var(--primary);
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .login-header p {
            color: #6c757d;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .user-type-selector {
            display: flex;
            margin-bottom: 1.5rem;
            border-radius: 12px;
            overflow: hidden;
            background: var(--light-bg);
            border: 2px solid #e9ecef;
        }

        .user-type-option {
            flex: 1;
            padding: 0.75rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            position: relative;
            overflow: hidden;
        }

        .user-type-option.active {
            color: white;
        }

        .user-type-option.cashier.active {
            background: var(--cashier-color);
        }

        .user-type-option.stock.active {
            background: var(--cashier-color);
        }

        .user-type-option:not(.active):hover {
            background: rgba(0,0,0,0.05);
        }

        .user-type-option i {
            margin-right: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
            text-align: left;
        }

        .form-group label {
            display: block;
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .input-container {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            color: var(--primary);
            font-size: 1.1rem;
            z-index: 2;
            transition: all 0.3s ease;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--light-bg);
            font-weight: 500;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 
                0 0 0 0.2rem rgba(90, 61, 126, 0.15),
                inset 0 0 10px rgba(90, 61, 126, 0.1);
            background: var(--white);
            transform: translateY(-2px);
        }

        .form-group:focus-within .input-icon {
            color: var(--secondary);
            transform: scale(1.1);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            color: #6c757d;
            cursor: pointer;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary);
            transform: scale(1.1);
        }

        .login-btn {
            width: 100%;
            padding: 1rem;
            background: var(--gradient);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow);
            letter-spacing: 0.5px;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:active {
            transform: translateY(-1px);
        }

        .login-btn i {
            margin-right: 0.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .error-message, .success-message {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
            animation: slideInDown 0.5s ease-out;
            text-align: left;
            position: relative;
            padding-left: 3rem;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-message::before, .success-message::before {
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
        }

        .error-message {
            background: #fdf2f2;
            color: var(--accent);
            border: 1px solid #f8d7da;
        }

        .error-message::before {
            content: '\f06a';
            color: var(--accent);
        }

        .success-message {
            background: #f2fdf2;
            color: var(--success);
            border: 1px solid #d4edda;
        }

        .success-message::before {
            content: '\f058';
            color: var(--success);
        }

        .footer-note {
            margin-top: 2rem;
            color: #6c757d;
            font-size: 0.85rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }

        .footer-note a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .footer-note a:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        /* Floating particles */
        .particles {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 0;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            animation: floatParticle 15s infinite linear;
        }

        @keyframes floatParticle {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100px) rotate(360deg);
                opacity: 0;
            }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-container {
                margin: 0;
                padding: 2rem 1.5rem;
                border-radius: 15px;
            }

            .login-header h1 {
                font-size: 1.5rem;
            }

            .logo {
                width: 70px;
                height: 70px;
            }

            .logo i {
                font-size: 2rem;
            }

            .form-group input {
                padding: 0.7rem 0.9rem 0.7rem 2.8rem;
            }

            .input-icon {
                left: 12px;
                font-size: 1rem;
            }
        }

        @media (max-width: 360px) {
            .login-container {
                padding: 1.5rem 1rem;
            }
            
            .login-header h1 {
                font-size: 1.3rem;
            }
            
            .login-btn {
                padding: 0.8rem;
                font-size: 1rem;
            }
        }

        .logo-container {
            text-align: center;
            position: relative;
        }

        .logo-container img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 0 15px rgba(255,255,255,0.5);
            animation: logoPulse 2s infinite;
            transition: var(--transition);
        }

        .logo-container img:hover {
            transform: scale(1.1);
            box-shadow: 0 0 25px rgba(255,255,255,0.7);
        }
        .logo-container img {
    animation: 
        float 4s ease-in-out infinite,
        logoGlow 3s ease-in-out infinite;
    transition: all 0.5s ease;
}

.logo-container img:hover {
    animation: 
        float 2s ease-in-out infinite,
        logoGlow 1s ease-in-out infinite,
        gradientShine 3s linear infinite;
    transform: scale(1.15);
}
@keyframes gradientShine {
    0% {
        filter: hue-rotate(0deg);
    }
    100% {
        filter: hue-rotate(360deg);
    }
}

.logo-container img:hover {
    animation: gradientShine 2s linear infinite;
    box-shadow: 0 0 30px rgba(255,255,255,0.8);
}


    </style>
</head>
<body>
    <div class="particles" id="particles"></div>
    
    <div class="login-container">
        <div class="login-header">
            <div class="logo-container">
            <img src="assets/img/logo.jpg" alt="kids berry">
        </div>
            <h1>KIDS Berry</h1>
            <p>Welcome! Please select your role and sign in.</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm">
            <div class="user-type-selector">
                <div class="user-type-option cashier active" data-type="cashier">
                    <i class="fas fa-cash-register"></i> Cashier
                </div>
                <div class="user-type-option stock" data-type="stock_keeper">
                    <i class="fas fa-warehouse"></i> Stock Keeper
                </div>
            </div>
            <input type="hidden" name="user_type" id="user_type" value="cashier">

            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-container">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email" required placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-container">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" required placeholder="Enter your password">
                    <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                </div>
            </div>

            <button type="submit" class="login-btn">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>

        <div class="footer-note">
            <p>Need assistance? <a href="admin_contact.php">Contact administrator</a></p>
        </div>
    </div>

    <script>

        setTimeout(function() {
    window.location.reload();
}, 40000);

        // Auto-focus on email input
        document.getElementById('email').focus();

        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // User type selection
        const userTypeOptions = document.querySelectorAll('.user-type-option');
        const userTypeInput = document.getElementById('user_type');
        
        userTypeOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove active class from all options
                userTypeOptions.forEach(opt => opt.classList.remove('active'));
                
                // Add active class to clicked option
                this.classList.add('active');
                
                // Update hidden input value
                userTypeInput.value = this.getAttribute('data-type');
                
                // Update button text based on selection
                const loginBtn = document.querySelector('.login-btn');
                if (userTypeInput.value === 'cashier') {
                    loginBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In as Cashier';
                } else {
                    loginBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In as Stock Keeper';
                }
            });
        });

        // Form validation enhancement
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in all fields.');
                return;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return;
            }
        });

        // Create floating particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 15;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random properties
                const size = Math.random() * 10 + 5;
                const left = Math.random() * 100;
                const animationDuration = Math.random() * 20 + 10;
                const animationDelay = Math.random() * 5;
                
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                particle.style.left = `${left}%`;
                particle.style.animationDuration = `${animationDuration}s`;
                particle.style.animationDelay = `${animationDelay}s`;
                
                particlesContainer.appendChild(particle);
            }
        }

        // Initialize particles when page loads
        window.addEventListener('load', createParticles);

        // Add input focus effects
        const inputs = document.querySelectorAll('input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.parentElement.classList.remove('focused');
            });
        });
    </script>
</body>
</html>