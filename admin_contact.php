<?php
session_start();

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$success_message = '';
$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $user_type = mysqli_real_escape_string($conn, $_POST['user_type']);
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);
    
    $sql = "INSERT INTO admin_contact_requests (name, email, user_type, subject, message) 
            VALUES ('$name', '$email', '$user_type', '$subject', '$message')";
    
    if ($conn->query($sql) === TRUE) {
        $success_message = "Your request has been submitted successfully! We'll get back to you soon.";
    } else {
        $error_message = "Error submitting your request. Please try again.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Administrator - KIDS Berry</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Same CSS as login page for consistency */
        :root {
            --primary: #5a3d7e;
            --secondary: #28a745;
            --accent: #dc3545;
            --success: #28a745;
            --light-bg: #f8f9fa;
            --white: #ffffff;
            --shadow: 0 4px 15px rgba(90, 61, 126, 0.2);
            --gradient: linear-gradient(135deg, var(--primary) 0%, #7b68ee 50%, var(--secondary) 100%);
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
            padding: 15px;
            overflow-x: hidden;
        }

        .contact-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 500px;
            text-align: center;
            transform: translateY(20px);
            opacity: 0;
            transition: all 0.5s ease;
        }

        .contact-container.visible {
            transform: translateY(0);
            opacity: 1;
        }

        .contact-header {
            margin-bottom: 2rem;
        }

        .logo {
            width: 70px;
            height: 70px;
            background: var(--gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: var(--shadow);
            transform: scale(0);
            animation: logo-appear 0.6s ease forwards 0.3s;
        }

        @keyframes logo-appear {
            0% { transform: scale(0) rotate(-180deg); }
            70% { transform: scale(1.1) rotate(10deg); }
            100% { transform: scale(1) rotate(0); }
        }

        .logo i {
            font-size: 2rem;
            color: white;
        }

        .contact-header h1 {
            color: var(--primary);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            opacity: 0;
            animation: fade-in 0.5s ease forwards 0.5s;
        }

        .contact-header p {
            color: #6c757d;
            font-size: 0.9rem;
            opacity: 0;
            animation: fade-in 0.5s ease forwards 0.7s;
        }

        @keyframes fade-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
            opacity: 0;
            transform: translateX(-20px);
            transition: all 0.4s ease;
        }

        .form-group.visible {
            opacity: 1;
            transform: translateX(0);
        }

        .form-group label {
            display: block;
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 0.5rem;
            transition: color 0.3s ease;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--light-bg);
        }

        .form-group textarea {
            height: 120px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(90, 61, 126, 0.15);
            background: var(--white);
            transform: translateY(-2px);
        }

        .form-group.focused label {
            color: var(--secondary);
        }

        .submit-btn {
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
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.4s ease 1s;
        }

        .submit-btn.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn::after {
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

        .submit-btn:focus:not(:active)::after {
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

        .back-btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.4s ease 1.2s;
        }

        .back-btn.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .back-btn:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .error-message, .success-message {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
            text-align: left;
            opacity: 0;
            transform: translateY(-10px);
            animation: message-appear 0.5s ease forwards;
        }

        @keyframes message-appear {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-message {
            background: #fdf2f2;
            color: var(--accent);
            border: 1px solid #f8d7da;
        }

        .success-message {
            background: #f2fdf2;
            color: var(--success);
            border: 1px solid #d4edda;
        }

        .confetti {
            position: fixed;
            width: 10px;
            height: 10px;
            background-color: var(--primary);
            opacity: 0;
            z-index: 1000;
        }

        .floating-particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background-color: rgba(255, 255, 255, 0.5);
            border-radius: 50%;
            animation: float 15s infinite linear;
        }

        @keyframes float {
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

        .typing-indicator {
            display: none;
            font-size: 0.9rem;
            color: var(--primary);
            margin-top: 0.5rem;
            text-align: left;
        }

        .typing-indicator span {
            display: inline-block;
            animation: typing 1.4s infinite;
        }

        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }

        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes typing {
            0%, 60%, 100% {
                transform: translateY(0);
            }
            30% {
                transform: translateY(-5px);
            }
        }

        .character-count {
            text-align: right;
            font-size: 0.8rem;
            margin-top: 0.25rem;
            color: #6c757d;
        }

        .character-count.near-limit {
            color: #ffc107;
        }

        .character-count.over-limit {
            color: var(--accent);
        }
    </style>
</head>
<body>
    <div class="floating-particles" id="particles"></div>
    
    <div class="contact-container" id="contactContainer">
        <div class="contact-header">
            <div class="logo">
                <i class="fas fa-headset"></i>
            </div>
            <h1>Contact Administrator</h1>
            <p>Need assistance? Fill out the form below and we'll help you.</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="contactForm">
            <div class="form-group" id="nameGroup">
                <label for="name">Your Name</label>
                <input type="text" id="name" name="name" required placeholder="Enter your full name">
            </div>

            <div class="form-group" id="emailGroup">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required placeholder="Enter your email">
            </div>

            <div class="form-group" id="userTypeGroup">
                <label for="user_type">User Type</label>
                <select id="user_type" name="user_type" required>
                    <option value="">Select your role</option>
                    <option value="cashier">Cashier</option>
                    <option value="stock_keeper">Stock Keeper</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-group" id="subjectGroup">
                <label for="subject">Subject</label>
                <input type="text" id="subject" name="subject" required placeholder="Brief description of your issue">
            </div>

            <div class="form-group" id="messageGroup">
                <label for="message">Message</label>
                <textarea id="message" name="message" required placeholder="Describe your issue in detail..." maxlength="500"></textarea>
                <div class="character-count" id="charCount">0/500</div>
                <div class="typing-indicator" id="typingIndicator">
                    Typing<span>.</span><span>.</span><span>.</span>
                </div>
            </div>

            <button type="submit" class="submit-btn" id="submitBtn">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
        </form>

        <a href="index.php" class="back-btn" id="backBtn">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize animations
            initAnimations();
            
            // Create floating particles
            createParticles();
            
            // Initialize form interactions
            initFormInteractions();
            
            // Initialize character counter for message
            initCharacterCounter();
            
            // Initialize typing indicator
            initTypingIndicator();
        });

        function initAnimations() {
            // Make container visible with animation
            const container = document.getElementById('contactContainer');
            setTimeout(() => {
                container.classList.add('visible');
            }, 100);
            
            // Animate form groups sequentially
            const formGroups = document.querySelectorAll('.form-group');
            formGroups.forEach((group, index) => {
                setTimeout(() => {
                    group.classList.add('visible');
                }, 800 + (index * 150));
            });
            
            // Animate submit button
            const submitBtn = document.getElementById('submitBtn');
            setTimeout(() => {
                submitBtn.classList.add('visible');
            }, 1500);
            
            // Animate back button
            const backBtn = document.getElementById('backBtn');
            setTimeout(() => {
                backBtn.classList.add('visible');
            }, 1600);
        }

        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 20;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random position and animation delay
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.animationDelay = `${Math.random() * 15}s`;
                
                // Random size
                const size = 2 + Math.random() * 4;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random color from theme
                const colors = ['#5a3d7e', '#28a745', '#7b68ee', '#dc3545'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                
                particlesContainer.appendChild(particle);
            }
        }

        function initFormInteractions() {
            // Add focus effects to form inputs
            const inputs = document.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                const formGroup = input.closest('.form-group');
                
                input.addEventListener('focus', () => {
                    formGroup.classList.add('focused');
                });
                
                input.addEventListener('blur', () => {
                    if (!input.value) {
                        formGroup.classList.remove('focused');
                    }
                });
                
                // Add validation styling
                input.addEventListener('input', () => {
                    if (input.validity.valid) {
                        formGroup.classList.remove('invalid');
                        formGroup.classList.add('valid');
                    } else {
                        formGroup.classList.remove('valid');
                        formGroup.classList.add('invalid');
                    }
                });
            });
            
            // Form submission with validation
            const form = document.getElementById('contactForm');
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Basic validation
                let isValid = true;
                const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
                
                inputs.forEach(input => {
                    if (!input.value.trim()) {
                        isValid = false;
                        input.style.borderColor = 'var(--accent)';
                    } else {
                        input.style.borderColor = '';
                    }
                });
                
                if (isValid) {
                    // Show loading state
                    const submitBtn = document.getElementById('submitBtn');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                    submitBtn.disabled = true;
                    
                    // Create confetti effect on successful validation
                    createConfetti();
                    
                    // Submit the form after a short delay to show the animation
                    setTimeout(() => {
                        form.submit();
                    }, 1500);
                } else {
                    // Shake animation for invalid form
                    form.classList.add('shake');
                    setTimeout(() => {
                        form.classList.remove('shake');
                    }, 500);
                }
            });
        }

        function initCharacterCounter() {
            const messageTextarea = document.getElementById('message');
            const charCount = document.getElementById('charCount');
            
            messageTextarea.addEventListener('input', function() {
                const count = this.value.length;
                charCount.textContent = `${count}/500`;
                
                // Update styling based on character count
                if (count > 450) {
                    charCount.classList.add('near-limit');
                    charCount.classList.remove('over-limit');
                } else if (count > 500) {
                    charCount.classList.remove('near-limit');
                    charCount.classList.add('over-limit');
                } else {
                    charCount.classList.remove('near-limit', 'over-limit');
                }
            });
        }

        function initTypingIndicator() {
            const messageTextarea = document.getElementById('message');
            const typingIndicator = document.getElementById('typingIndicator');
            let typingTimer;
            
            messageTextarea.addEventListener('input', function() {
                // Show typing indicator
                typingIndicator.style.display = 'block';
                
                // Clear existing timer
                clearTimeout(typingTimer);
                
                // Hide typing indicator after user stops typing for 1 second
                typingTimer = setTimeout(() => {
                    typingIndicator.style.display = 'none';
                }, 1000);
            });
        }

        function createConfetti() {
            const confettiCount = 50;
            const colors = ['#5a3d7e', '#28a745', '#7b68ee', '#dc3545', '#ffc107'];
            
            for (let i = 0; i < confettiCount; i++) {
                const confetti = document.createElement('div');
                confetti.classList.add('confetti');
                
                // Random position
                confetti.style.left = `${Math.random() * 100}%`;
                confetti.style.top = `${Math.random() * 100}%`;
                
                // Random color
                const color = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.backgroundColor = color;
                
                // Random size
                const size = 5 + Math.random() * 10;
                confetti.style.width = `${size}px`;
                confetti.style.height = `${size}px`;
                
                // Random shape
                if (Math.random() > 0.5) {
                    confetti.style.borderRadius = '50%';
                } else {
                    confetti.style.transform = `rotate(${Math.random() * 360}deg)`;
                }
                
                document.body.appendChild(confetti);
                
                // Animate confetti
                const animation = confetti.animate([
                    { 
                        opacity: 1, 
                        transform: `translateY(0) rotate(0deg)` 
                    },
                    { 
                        opacity: 1, 
                        transform: `translateY(${window.innerHeight}px) rotate(${360 + Math.random() * 360}deg)` 
                    }
                ], {
                    duration: 1000 + Math.random() * 2000,
                    easing: 'cubic-bezier(0.1, 0.8, 0.2, 1)'
                });
                
                // Remove confetti after animation completes
                animation.onfinish = () => {
                    confetti.remove();
                };
            }
        }
    </script>
</body>
</html>