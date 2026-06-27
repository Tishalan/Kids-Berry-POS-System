<?php
session_start();

// If admin is already logged in, redirect to dashboard
if (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}

// Database connection
$servername = "127.0.0.1:3306";
$username = "root";
$password = "";
$dbname = "kids_berry";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";
$message_type = ""; // success or error

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validate inputs
    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $message = "All fields are required!";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address!";
        $message_type = "error";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters long!";
        $message_type = "error";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match!";
        $message_type = "error";
    } else {
        // Check if email already exists
        $check_stmt = $conn->prepare("SELECT admin_id FROM admin_users WHERE email = ?");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $message = "Email already registered! Please use a different email.";
            $message_type = "error";
        } else {
            // Hash password and insert new admin
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $insert_stmt = $conn->prepare("INSERT INTO admin_users (full_name, email, password, status) VALUES (?, ?, ?, 'active')");
            $insert_stmt->bind_param("sss", $full_name, $email, $hashed_password);
            
            if ($insert_stmt->execute()) {
                $message = "Admin account created successfully! You can now login.";
                $message_type = "success";
                
                // Clear form fields
                $full_name = $email = "";
            } else {
                $message = "Error creating account. Please try again.";
                $message_type = "error";
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kids Berry - Admin Registration</title>
    <style>
        :root {
            --primary-purple: #6a0dad;
            --light-purple: #8a2be2;
            --dark-purple: #4b0082;
            --light-green: #90ee90;
            --medium-green: #32cd32;
            --white: #ffffff;
            --light-gray: #f5f5f5;
            --error-red: #ff4757;
            --success-green: #2ed573;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--light-purple), var(--dark-purple));
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            display: flex;
            width: 900px;
            height: 670px;
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
            animation: fadeIn 1s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .left-section {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-purple), var(--dark-purple));
            color: var(--white);
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .left-section::before {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            background: var(--light-green);
            border-radius: 50%;
            top: -50px;
            right: -50px;
            opacity: 0.2;
        }

        .left-section::after {
            content: '';
            position: absolute;
            width: 150px;
            height: 150px;
            background: var(--light-green);
            border-radius: 50%;
            bottom: -30px;
            left: -30px;
            opacity: 0.2;
        }

        .logo {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .logo span {
            color: var(--light-green);
        }

        .logo i {
            margin-right: 10px;
            font-size: 36px;
        }

        .welcome-text {
            font-size: 28px;
            margin-bottom: 15px;
            animation: slideInLeft 1s ease-out;
        }

        .sub-text {
            font-size: 16px;
            line-height: 1.6;
            opacity: 0.9;
            animation: slideInLeft 1.2s ease-out;
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .right-section {
            flex: 1;
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--white);
        }

        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .register-header h2 {
            color: var(--primary-purple);
            font-size: 28px;
            margin-bottom: 10px;
        }

        .register-header p {
            color: #666;
            font-size: 14px;
        }

        .message {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
        }

        .message.success {
            background-color: rgba(46, 213, 115, 0.2);
            color: var(--success-green);
            border: 1px solid var(--success-green);
        }

        .message.error {
            background-color: rgba(255, 71, 87, 0.2);
            color: var(--error-red);
            border: 1px solid var(--error-red);
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-purple);
        }

        .form-control {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(106, 13, 173, 0.1);
        }

        .form-control:focus + .focus-border {
            width: 100%;
        }

        .focus-border {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-purple);
            transition: width 0.3s;
        }

        .password-strength {
            margin-top: 5px;
            font-size: 12px;
        }

        .strength-weak { color: var(--error-red); }
        .strength-medium { color: orange; }
        .strength-strong { color: var(--success-green); }

        .btn-register {
            background: linear-gradient(135deg, var(--primary-purple), var(--light-purple));
            color: var(--white);
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(106, 13, 173, 0.3);
            width: 100%;
            position: relative;
            overflow: hidden;
            margin-bottom: 15px;
        }

        .btn-register:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(106, 13, 173, 0.4);
        }

        .btn-register:active {
            transform: translateY(1px);
        }

        .btn-register::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }

        .btn-register:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }

        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            100% {
                transform: scale(20, 20);
                opacity: 0;
            }
        }

        .login-link {
            text-align: center;
            margin-top: 10px;
        }

        .login-link a {
            color: var(--primary-purple);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .login-link a:hover {
            color: var(--dark-purple);
            text-decoration: underline;
        }

        .error-message {
            color: #ff4757;
            font-size: 14px;
            margin-top: 5px;
            display: none;
        }

        .floating-shapes {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            overflow: hidden;
            z-index: 0;
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            background: var(--light-green);
            opacity: 0.1;
            animation: float 15s infinite ease-in-out;
        }

        .shape:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 10%;
            left: 20%;
            animation-delay: 0s;
        }

        .shape:nth-child(2) {
            width: 100px;
            height: 100px;
            top: 60%;
            left: 70%;
            animation-delay: 2s;
        }

        .shape:nth-child(3) {
            width: 60px;
            height: 60px;
            top: 40%;
            left: 10%;
            animation-delay: 4s;
        }

        .shape:nth-child(4) {
            width: 120px;
            height: 120px;
            top: 20%;
            left: 80%;
            animation-delay: 6s;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            33% {
                transform: translateY(-20px) rotate(120deg);
            }
            66% {
                transform: translateY(10px) rotate(240deg);
            }
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                height: auto;
                width: 100%;
            }
            
            .left-section, .right-section {
                padding: 30px;
            }
            
            .left-section {
                text-align: center;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="left-section">
            <div class="floating-shapes">
                <div class="shape"></div>
                <div class="shape"></div>
                <div class="shape"></div>
                <div class="shape"></div>
            </div>
            <div class="logo">
                <i class="fas fa-berry"></i>
                Kids <span>Berry</span>
            </div>
            <h1 class="welcome-text">Join Our Team!</h1>
            <p class="sub-text">Create a new admin account to manage products, track sales, and oversee store operations with full administrative privileges.</p>
        </div>
        
        <div class="right-section">
            <div class="register-header">
                <h2>Admin Registration</h2>
                <p>Create your admin account to get started</p>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form id="registerForm" method="POST" action="">
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <div class="input-with-icon">
                        <i class="fas fa-user"></i>
                        <input type="text" class="form-control" id="full_name" name="full_name" 
                               placeholder="Enter your full name" required 
                               value="<?php echo isset($full_name) ? htmlspecialchars($full_name) : ''; ?>">
                        <div class="focus-border"></div>
                    </div>
                    <div class="error-message" id="nameError"></div>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-with-icon">
                        <i class="fas fa-envelope"></i>
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="Enter your email" required
                               value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                        <div class="focus-border"></div>
                    </div>
                    <div class="error-message" id="emailError"></div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter your password" required>
                        <div class="focus-border"></div>
                    </div>
                    <div class="password-strength" id="passwordStrength"></div>
                    <div class="error-message" id="passwordError"></div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-check-circle"></i>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               placeholder="Confirm your password" required>
                        <div class="focus-border"></div>
                    </div>
                    <div class="password-strength" id="passwordMatch"></div>
                    <div class="error-message" id="confirmPasswordError"></div>
                </div>
                
                <button type="submit" class="btn-register">Register</button>
                
                <div class="login-link">
                    Already have an admin account? <a href="admin_login.php">Login here</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Reset error messages
            document.getElementById('nameError').style.display = 'none';
            document.getElementById('emailError').style.display = 'none';
            document.getElementById('passwordError').style.display = 'none';
            document.getElementById('confirmPasswordError').style.display = 'none';
            
            // Get form values
            const fullName = document.getElementById('full_name').value;
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // Simple validation
            let isValid = true;
            
            if (!fullName) {
                document.getElementById('nameError').textContent = 'Full name is required';
                document.getElementById('nameError').style.display = 'block';
                isValid = false;
            } else if (fullName.length < 2) {
                document.getElementById('nameError').textContent = 'Full name must be at least 2 characters';
                document.getElementById('nameError').style.display = 'block';
                isValid = false;
            }
            
            if (!email) {
                document.getElementById('emailError').textContent = 'Email is required';
                document.getElementById('emailError').style.display = 'block';
                isValid = false;
            } else if (!isValidEmail(email)) {
                document.getElementById('emailError').textContent = 'Please enter a valid email address';
                document.getElementById('emailError').style.display = 'block';
                isValid = false;
            }
            
            if (!password) {
                document.getElementById('passwordError').textContent = 'Password is required';
                document.getElementById('passwordError').style.display = 'block';
                isValid = false;
            } else if (password.length < 6) {
                document.getElementById('passwordError').textContent = 'Password must be at least 6 characters';
                document.getElementById('passwordError').style.display = 'block';
                isValid = false;
            }
            
            if (!confirmPassword) {
                document.getElementById('confirmPasswordError').textContent = 'Please confirm your password';
                document.getElementById('confirmPasswordError').style.display = 'block';
                isValid = false;
            } else if (password !== confirmPassword) {
                document.getElementById('confirmPasswordError').textContent = 'Passwords do not match';
                document.getElementById('confirmPasswordError').style.display = 'block';
                isValid = false;
            }
            
            if (isValid) {
                // If validation passes, submit the form
                this.submit();
            }
        });
        
        function isValidEmail(email) {
            const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(String(email).toLowerCase());
        }
        
        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthElement = document.getElementById('passwordStrength');
            
            if (password.length === 0) {
                strengthElement.textContent = '';
                strengthElement.className = 'password-strength';
                return;
            }
            
            let strength = 'Weak';
            let strengthClass = 'strength-weak';
            
            if (password.length >= 8) {
                strength = 'Medium';
                strengthClass = 'strength-medium';
            }
            
            if (password.length >= 10 && /[A-Z]/.test(password) && /[0-9]/.test(password) && /[^A-Za-z0-9]/.test(password)) {
                strength = 'Strong';
                strengthClass = 'strength-strong';
            }
            
            strengthElement.textContent = `Password strength: ${strength}`;
            strengthElement.className = `password-strength ${strengthClass}`;
        });
        
        // Password match indicator
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const matchElement = document.getElementById('passwordMatch');
            
            if (confirmPassword.length === 0) {
                matchElement.textContent = '';
                matchElement.className = 'password-strength';
                return;
            }
            
            if (password === confirmPassword) {
                matchElement.textContent = '✓ Passwords match';
                matchElement.className = 'password-strength strength-strong';
            } else {
                matchElement.textContent = '✗ Passwords do not match';
                matchElement.className = 'password-strength strength-weak';
            }
        });
        
        // Add focus effects to form inputs
        const inputs = document.querySelectorAll('.form-control');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.querySelector('.focus-border').style.width = '100%';
            });
            
            input.addEventListener('blur', function() {
                if (!this.value) {
                    this.parentElement.querySelector('.focus-border').style.width = '0';
                }
            });
        });
    </script>
</body>
</html>