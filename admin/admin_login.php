<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";


// Database connection
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle admin login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password_input = $_POST['password'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && $password_input === $admin['password']) {
            $_SESSION['admin_id'] = $admin['admin_id'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role'];
            
            // Log admin activity
            $log_stmt = $pdo->prepare("INSERT INTO admin_activity (admin_id, action) VALUES (?, ?)");
            $log_stmt->execute([$admin['admin_id'], 'Admin logged in']);
            
            $_SESSION['message'] = "Welcome back, " . $admin['full_name'] . "!";
            header("Location: admin_dashboard.php");
            exit();
        } else {
            $login_error = "Invalid email or password!";
        }
    } catch(PDOException $e) {
        $login_error = "Login failed. Please try again.";
    }
}

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Velvet Vogue</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #8a2be2;
            --secondary-color: #90ee90;
            --accent-color: #f0f8ff;
            --text-dark: #333;
            --text-light: #fff;
            --bg-light: #f9f9f9;
            --success-color: #27ae60;
            --warning-color: #e67e22;
            --error-color: #e74c3c;
            --info-color: #3498db;
            --transition: all 0.3s ease;
            --shadow: 0 10px 30px rgba(138, 43, 226, 0.1);
            --shadow-hover: 0 20px 40px rgba(138, 43, 226, 0.2);
            --admin-primary: #6a0dad;
            --admin-secondary: #98fb98;
            --gradient-primary: linear-gradient(135deg, #8a2be2 0%, #9370db 100%);
            --gradient-secondary: linear-gradient(135deg, var(--admin-primary) 0%, #7b68ee 100%);
            --purple-light: #e6e6fa;
            --purple-dark: #4b0082;
            --green-light: #f0fff0;
            --green-bright: #00ff7f;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            line-height: 1.6;
            color: var(--text-dark);
            background: var(--gradient-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Playfair Display', serif;
            font-weight: 600;
        }

        /* Animated Background */
        .bg-animation {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .bg-bubble {
            position: absolute;
            border-radius: 50%;
            background: rgba(144, 238, 144, 0.1);
            animation: float 15s infinite linear;
            box-shadow: 0 0 20px rgba(144, 238, 144, 0.3);
        }

        .bg-bubble:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
            background: rgba(138, 43, 226, 0.1);
        }

        .bg-bubble:nth-child(2) {
            width: 100px;
            height: 100px;
            top: 60%;
            left: 80%;
            animation-delay: 2s;
            background: rgba(144, 238, 144, 0.15);
        }

        .bg-bubble:nth-child(3) {
            width: 60px;
            height: 60px;
            top: 80%;
            left: 20%;
            animation-delay: 4s;
            background: rgba(138, 43, 226, 0.1);
        }

        .bg-bubble:nth-child(4) {
            width: 120px;
            height: 120px;
            top: 30%;
            left: 70%;
            animation-delay: 6s;
            background: rgba(144, 238, 144, 0.15);
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            33% {
                transform: translateY(-30px) rotate(120deg);
            }
            66% {
                transform: translateY(20px) rotate(240deg);
            }
        }

        /* Admin Container */
        .admin-container {
            display: flex;
            max-width: 450px;
            width: 100%;
            background: white;
            border-radius: 25px;
            overflow: hidden;
            box-shadow: var(--shadow-hover);
            animation: slideUp 0.8s ease;
            position: relative;
            z-index: 10;
            border: 2px solid var(--purple-light);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Admin Header */
        .admin-header {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            background: var(--gradient-secondary);
            color: var(--text-light);
            padding: 1.5rem;
            text-align: center;
            z-index: 10;
            box-shadow: 0 4px 15px rgba(106, 13, 173, 0.2);
        }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        /* Admin Section */
        .admin-section {
            flex: 1;
            padding: 6rem 2rem 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: white;
            position: relative;
        }

        .form-title {
            font-size: 1.8rem;
            color: var(--admin-primary);
            margin-bottom: 0.5rem;
            text-align: center;
            background: linear-gradient(135deg, var(--admin-primary), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .form-subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--admin-primary);
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 1rem 1.5rem;
            border: 2px solid #e1e1e1;
            border-radius: 15px;
            font-family: 'Montserrat', sans-serif;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--bg-light);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--secondary-color);
            background: white;
            box-shadow: 0 0 0 3px rgba(144, 238, 144, 0.3);
            transform: translateY(-2px);
        }

        .form-input.error {
            border-color: var(--error-color);
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(5px);
            background: none;
            border: none;
            cursor: pointer;
            color: #666;
            font-size: 1.2rem;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--admin-primary);
        }

        /* Buttons */
        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-family: 'Montserrat', sans-serif;
            font-size: 1rem;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--admin-primary), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(138, 43, 226, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--purple-dark), var(--green-bright));
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(138, 43, 226, 0.4);
        }

        .btn-primary:active {
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: transparent;
            color: var(--admin-primary);
            border: 2px solid var(--secondary-color);
        }

        .btn-secondary:hover {
            background: var(--secondary-color);
            color: var(--text-dark);
            transform: translateY(-3px);
        }

        /* Change Password Button Styles */
        .change-password-btn {
            background: transparent;
            border: none;
            color: var(--admin-primary);
            text-decoration: none;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0;
            width: auto;
            transition: var(--transition);
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin: 1rem auto;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            overflow: hidden;
            z-index: 1;
            border: 1px solid var(--admin-primary);
        }

        .change-password-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0%;
            height: 100%;
            background: linear-gradient(135deg, var(--admin-primary), var(--secondary-color));
            transition: var(--transition);
            z-index: -1;
            border-radius: 25px;
        }

        .change-password-btn:hover::before {
            width: 100%;
        }

        .change-password-btn:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(138, 43, 226, 0.3);
            border-color: transparent;
        }

        .change-password-btn:active {
            transform: translateY(0);
        }

        .change-password-btn i {
            transition: var(--transition);
        }

        .change-password-btn:hover i {
            transform: rotate(-10deg) scale(1.1);
            color: white;
        }

        /* Button Ripple Effect */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }

        @keyframes ripple {
            to {
                transform: scale(2.5);
                opacity: 0;
            }
        }

        /* Security Features */
        .security-features {
            background: var(--purple-light);
            border-radius: 15px;
            padding: 1.5rem;
            margin-top: 2rem;
            border-left: 4px solid var(--secondary-color);
            transition: var(--transition);
        }

        .security-features:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(138, 43, 226, 0.1);
        }

        .security-title {
            font-size: 1rem;
            color: var(--admin-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .security-list {
            list-style: none;
            font-size: 0.9rem;
            color: #666;
        }

        .security-list li {
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .security-list li:hover {
            color: var(--admin-primary);
            transform: translateX(5px);
        }

        .security-list li::before {
            content: '✓';
            color: var(--success-color);
            font-weight: bold;
        }

        /* Error Messages */
        .error-message {
            background: #fee;
            color: var(--error-color);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--error-color);
            animation: shake 0.5s ease;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .success-message {
            background: var(--green-light);
            color: var(--success-color);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            border-left: 4px solid var(--success-color);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Back to Site */
        .back-to-site {
            position: absolute;
            top: 1rem;
            left: 1rem;
            z-index: 100;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 25px;
            backdrop-filter: blur(10px);
            font-size: 0.9rem;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateX(-5px);
        }

        /* Admin Info */
        .admin-info {
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 0.8rem;
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--admin-primary), var(--secondary-color));
            color: white;
            border-radius: 10px;
            box-shadow: var(--shadow-hover);
            transform: translateX(400px);
            transition: transform 0.3s ease;
            z-index: 1001;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notification.show {
            transform: translateX(0);
        }

        /* Floating Elements */
        .floating-element {
            position: absolute;
            background: rgba(138, 43, 226, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
            z-index: 1;
        }

        .float-1 {
            width: 80px;
            height: 80px;
            top: 20%;
            right: -20px;
            animation-delay: 0s;
            background: rgba(144, 238, 144, 0.15);
        }

        .float-2 {
            width: 60px;
            height: 60px;
            bottom: 30%;
            left: -15px;
            animation-delay: 2s;
            background: rgba(138, 43, 226, 0.1);
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .admin-container {
                max-width: 100%;
                border-radius: 20px;
            }
            
            .admin-section {
                padding: 6rem 1.5rem 1.5rem;
            }
            
            .form-title {
                font-size: 1.5rem;
            }
            
            body {
                padding: 1rem;
            }
            
            .back-to-site {
                top: 0.5rem;
                left: 0.5rem;
            }
        }

        /* Form Icons */
        .form-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(5px);
            color: var(--admin-primary);
            font-size: 1.2rem;
        }

        .form-group.with-icon .form-input {
            padding-left: 3rem;
        }

        /* Pulse Animation for Important Elements */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        /* Key Animation for Change Password Button */
        @keyframes keyTurn {
            0% { transform: rotate(0deg); }
            25% { transform: rotate(-15deg); }
            75% { transform: rotate(15deg); }
            100% { transform: rotate(0deg); }
        }

        .key-animation {
            display: inline-block;
            animation: keyTurn 3s ease-in-out infinite;
        }

        /* Glow Effect */
        @keyframes glow {
            0%, 100% { 
                box-shadow: 0 0 5px rgba(138, 43, 226, 0.5);
            }
            50% { 
                box-shadow: 0 0 20px rgba(138, 43, 226, 0.8), 0 0 30px rgba(144, 238, 144, 0.6);
            }
        }

        .glow-effect {
            animation: glow 2s ease-in-out infinite;
        }

        /* Purple and Green Theme Enhancements */
        .form-group.with-icon .form-input:focus + .form-icon {
            color: var(--secondary-color);
            animation: iconBounce 0.5s ease;
        }

        @keyframes iconBounce {
            0%, 100% { transform: translateY(5px); }
            50% { transform: translateY(-3px); }
        }

        .admin-container::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, var(--admin-primary), var(--secondary-color), var(--admin-primary));
            z-index: -1;
            border-radius: 27px;
            animation: borderRotate 3s linear infinite;
        }

        @keyframes borderRotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-input::placeholder {
            color: #aaa;
            transition: var(--transition);
        }

        .form-input:focus::placeholder {
            color: var(--admin-primary);
            transform: translateX(5px);
        }
    </style>
</head>
<body>
    <!-- Animated Background -->
    <div class="bg-animation">
        <div class="bg-bubble"></div>
        <div class="bg-bubble"></div>
        <div class="bg-bubble"></div>
        <div class="bg-bubble"></div>
    </div>

    <!-- Back to Site -->
    <!-- <div class="back-to-site">
        <a href="home.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Back to Site
        </a>
    </div> -->

    <!-- Admin Container -->
    <div class="admin-container">
        <!-- Floating Elements -->
        <div class="floating-element float-1"></div>
        <div class="floating-element float-2"></div>
        
        <!-- Admin Header -->
        <div class="admin-header">
            <div class="admin-badge">
                <i class="fas fa-crown"></i>
                <span>Admin Panel - KIDS Berry</span>
            </div>
        </div>

        <!-- Admin Section -->
        <section class="admin-section">
            <!-- Login Form -->
            <form method="POST" class="admin-form active" id="loginForm">
                <input type="hidden" name="login" value="1">
                
                <h2 class="form-title">Admin Access</h2>
                <p class="form-subtitle">Secure administrator login</p>

                <?php if(isset($login_error)): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo $login_error; ?>
                    </div>
                <?php endif; ?>

                <?php if(isset($_SESSION['message'])): ?>
                    <div class="success-message">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
                    </div>
                <?php endif; ?>

                <div class="form-group with-icon">
                    <i class="fas fa-envelope form-icon"></i>
                    <label class="form-label">Admin Email</label>
                    <input type="email" name="email" class="form-input" placeholder="Enter admin email" required 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group with-icon">
                    <i class="fas fa-lock form-icon"></i>
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-input" placeholder="Enter admin password" required id="loginPassword">
                    <button type="button" class="password-toggle" onclick="togglePassword('loginPassword', this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <button type="submit" class="btn btn-primary pulse">
                    <i class="fas fa-unlock-alt"></i>
                    Access Admin Panel
                </button>

                <div style="text-align: center; margin-top: 1rem;">
                    <button type="button" class="change-password-btn glow-effect" onclick="window.location.href='changepassword.php'">
                        <i class="fas fa-key key-animation"></i> Change Password
                    </button>
                </div>

                <!-- <div class="security-features">
                    <h3 class="security-title">
                        <i class="fas fa-shield-alt"></i>
                        Security Features
                    </h3>
                    <ul class="security-list">
                        <li>Secure admin authentication</li>
                        <li>Activity logging</li>
                        <li>Session management</li>
                        <li>Password protection</li>
                    </ul>
                </div> -->
            </form>

            <!-- Admin Info -->
            <div class="admin-info">
                <p><i class="fas fa-code"></i> Velvet Vogue Admin Panel v1.0</p>
                <p><i class="fas fa-user-shield"></i> Restricted Access • Authorized Personnel Only</p>
            </div>
        </section>
    </div>

    <!-- JavaScript -->
    <script>
        // Password visibility toggle
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.getAttribute('type') === 'password') {
                input.setAttribute('type', 'text');
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.setAttribute('type', 'password');
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Input animations
        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
                this.classList.remove('error');
            });
            
            input.addEventListener('blur', function() {
                if (this.value === '') {
                    this.parentElement.classList.remove('focused');
                }
            });
        });

        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification';
            
            let icon = 'fa-info-circle';
            if (type === 'error') icon = 'fa-exclamation-triangle';
            if (type === 'success') icon = 'fa-check-circle';
            
            notification.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
            document.body.appendChild(notification);
            
            setTimeout(() => notification.classList.add('show'), 100);
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }

        // Auto-focus first input
        const firstInput = document.querySelector('.admin-form.active .form-input');
        if (firstInput) firstInput.focus();

        // Form submission loading states
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<div class="loading"></div> Processing...';
                    submitBtn.disabled = true;
                    
                    // Re-enable after 5 seconds (in case of error)
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 5000);
                }
            });
        });

        // Ripple effect for buttons
        document.querySelectorAll('.btn, .change-password-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                // Only create ripple for main buttons, not the change password button
                if(this.classList.contains('btn')) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';
                    ripple.classList.add('ripple');
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                }
            });
        });

        // Hover effect for change password button
        const changePasswordBtn = document.querySelector('.change-password-btn');
        if(changePasswordBtn) {
            changePasswordBtn.addEventListener('mouseenter', function() {
                this.classList.add('glow-effect');
            });
            
            changePasswordBtn.addEventListener('mouseleave', function() {
                this.classList.remove('glow-effect');
            });
        }

        // Enhanced input focus effects
        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('focus', function() {
                this.style.background = 'linear-gradient(45deg, #f0f8ff, #ffffff)';
            });
            
            input.addEventListener('blur', function() {
                this.style.background = 'var(--bg-light)';
            });
        });

        // Add floating animation to security features
        document.querySelectorAll('.security-features').forEach(feature => {
            feature.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
            });
            
            feature.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
    </script>
</body>
</html>