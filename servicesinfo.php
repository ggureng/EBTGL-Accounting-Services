<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Our Services - EBTGL Accounting Services</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary-blue: #4682B4;
      --light-blue: #b0c4de;
      --dark-blue: #2a5a80;
      --accent-blue: #5a96cf;
      --light-gray: #f8f9fa;
    }
    
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body, html {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      scroll-behavior: smooth;
      background-color: #f2f2f2;
      color: #333;
      line-height: 1.6;
    }

    nav {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 15px 30px;
      background: linear-gradient(135deg, var(--primary-blue), var(--accent-blue));
      position: sticky;
      top: 0;
      z-index: 1000;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .logo {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .logo-text {
      color: white;
      font-weight: bold;
      font-size: 1.8rem;
      text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
    }

    .nav-links a, .nav-links button {
      color: #fff;
      text-decoration: none;
      margin: 0 10px;
      font-weight: 600;
      background: none;
      border: none;
      font-size: 1.1rem;
      cursor: pointer;
      padding: 8px 15px;
      border-radius: 30px;
      transition: all 0.3s ease;
    }

    .nav-links a:hover, .nav-links button:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: translateY(-2px);
    }

    .hero {
      background: linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), url('images/services-bg.jpg') center/cover no-repeat;
      height: 90vh;
      color: white;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      padding: 0 20px;
    }

    .hero-content {
      max-width: 800px;
      animation: fadeInUp 1s ease-out;
    }

    .hero h1 {
      font-size: 3.5rem;
      margin-bottom: 20px;
      text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
    }

    .hero p {
      font-size: 1.5rem;
      margin-bottom: 40px;
    }

    .section {
      padding: 80px 20px;
      max-width: 1200px;
      margin: auto;
    }

    .section-title {
      text-align: center;
      margin-bottom: 60px;
      position: relative;
    }

    .section-title h2 {
      font-size: 2.5rem;
      color: var(--dark-blue);
      margin-bottom: 15px;
    }

    .section-title:after {
      content: '';
      display: block;
      width: 80px;
      height: 4px;
      background: linear-gradient(90deg, var(--primary-blue), var(--light-blue));
      margin: 10px auto;
      border-radius: 2px;
    }

    .services-container {
      display: flex;
      flex-direction: column;
      gap: 50px;
    }

    .service-block {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 40px;
      background: white;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0,0,0,0.08);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .service-block:hover {
      transform: translateY(-10px);
      box-shadow: 0 15px 40px rgba(0,0,0,0.15);
    }

    .service-block:nth-child(even) {
      flex-direction: row-reverse;
    }

    .service-block img {
      width: 45%;
      height: 300px;
      object-fit: cover;
      transition: transform 0.5s ease;
    }

    .service-block:hover img {
      transform: scale(1.03);
    }

    .service-text {
      width: 55%;
      padding: 40px;
      text-align: justify;
    }

    .service-text h2 {
      color: var(--dark-blue);
      font-size: 2rem;
      margin-bottom: 20px;
      position: relative;
    }

    .service-text h2:after {
      content: '';
      display: block;
      width: 60px;
      height: 4px;
      background: linear-gradient(90deg, var(--primary-blue), var(--light-blue));
      margin: 15px 0;
      border-radius: 2px;
    }

    .service-text p {
      font-size: 1.1rem;
      line-height: 1.8;
      color: #555;
    }

    .chat-toggle {
      position: fixed;
      bottom: 30px;
      right: 30px;
      width: 60px;
      height: 60px;
      background: linear-gradient(135deg, var(--primary-blue), var(--accent-blue));
      color: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      cursor: pointer;
      box-shadow: 0 5px 15px rgba(0,0,0,0.2);
      z-index: 100;
      transition: all 0.3s ease;
    }

    .chat-toggle:hover {
      transform: scale(1.1) rotate(10deg);
      box-shadow: 0 8px 20px rgba(0,0,0,0.3);
    }

    .chatbox {
      position: fixed;
      bottom: 100px;
      right: 30px;
      width: 350px;
      background: white;
      border-radius: 15px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0,0,0,0.15);
      z-index: 100;
      display: none;
      transform: translateY(20px);
      opacity: 0;
      transition: all 0.4s ease;
    }

    .chatbox.active {
      display: block;
      transform: translateY(0);
      opacity: 1;
    }

    .chat-header {
      background: linear-gradient(135deg, var(--primary-blue), var(--accent-blue));
      color: white;
      padding: 15px 20px;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .chat-messages {
      height: 300px;
      padding: 15px;
      overflow-y: auto;
      background: var(--light-gray);
    }

    .message {
      background: white;
      border-radius: 10px;
      padding: 10px 15px;
      margin-bottom: 15px;
      max-width: 80%;
      box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }

    .message.admin {
      background: var(--light-blue);
      margin-right: auto;
    }

    .message.user {
      background: #e6f2ff;
      margin-left: auto;
    }

    .chat-input {
      display: flex;
      padding: 15px;
      background: white;
      border-top: 1px solid #eee;
    }

    .chat-input input {
      flex: 1;
      padding: 12px 15px;
      border: 1px solid #ddd;
      border-radius: 30px;
      font-size: 1rem;
    }

    .chat-input button {
      background: var(--primary-blue);
      color: white;
      border: none;
      border-radius: 30px;
      padding: 10px 20px;
      margin-left: 10px;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .chat-input button:hover {
      background: var(--dark-blue);
    }

    footer {
      background: linear-gradient(135deg, var(--dark-blue), var(--primary-blue));
      color: white;
      padding: 60px 20px 30px;
      text-align: center;
    }

    .footer-content {
      max-width: 1200px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 40px;
      text-align: left;
    }

    .footer-column h3 {
      font-size: 1.4rem;
      margin-bottom: 20px;
      position: relative;
      display: inline-block;
    }

    .footer-column h3:after {
      content: '';
      display: block;
      width: 40px;
      height: 3px;
      background: var(--light-blue);
      margin-top: 10px;
    }

    .footer-column p, .footer-column a {
      color: rgba(255, 255, 255, 0.9);
      margin-bottom: 10px;
      display: block;
      text-decoration: none;
      transition: all 0.3s ease;
    }

    .footer-column a:hover {
      color: white;
      transform: translateX(5px);
    }

    .social-links {
      display: flex;
      gap: 15px;
      margin-top: 20px;
    }

    .social-links a {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
    }

    .social-links a:hover {
      background: var(--light-blue);
      transform: translateY(-5px);
    }

    .copyright {
      margin-top: 40px;
      padding-top: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      font-size: 0.9rem;
      color: rgba(255, 255, 255, 0.7);
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Popup Styles */
    .overlay {
      position: fixed;
      top: 0; 
      left: 0;
      width: 100vw; 
      height: 100vh;
      background: rgba(0, 0, 0, 0.5);
      display: flex; 
      align-items: center; 
      justify-content: center;
      z-index: 9999;
    }
    
    .hidden { 
      display: none; 
    }
    
    .popup {
      background: #f9f9f9;
      padding: 30px;
      border-radius: 15px;
      width: 100%;
      max-width: 450px;
      position: relative;
      box-shadow: 0 10px 30px rgba(0,0,0,0.2);
      text-align: center;
    }
    
    .close {
      position: absolute;
      top: 15px; 
      right: 20px;
      font-size: 28px;
      cursor: pointer;
      color: #666;
    }
    
    .field {
      display: block;
      width: 100%;
      padding: 12px 15px;
      margin: 15px 0;
      font-size: 16px;
      border: 1px solid #ddd;
      border-radius: 8px;
      transition: border-color 0.3s;
    }
    
    .field:focus {
      border-color: var(--primary-blue);
      outline: none;
      box-shadow: 0 0 0 3px rgba(70, 130, 180, 0.1);
    }
    
    .btn {
      padding: 12px;
      width: 100%;
      background: linear-gradient(135deg, var(--primary-blue), var(--accent-blue));
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      margin-top: 15px;
      font-weight: 600;
      font-size: 16px;
      transition: all 0.3s ease;
    }
    
    .btn:hover {
      background: linear-gradient(135deg, var(--dark-blue), var(--primary-blue));
      transform: translateY(-2px);
    }
    
    .toggle-text {
      font-size: 15px;
      margin-top: 20px;
      color: #666;
    }
    
    .toggle-text span, .toggle-text a {
      color: var(--primary-blue);
      text-decoration: underline;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    
    .toggle-text span:hover, .toggle-text a:hover {
      color: var(--dark-blue);
    }

    .status-message {
      margin-top: 15px;
      font-size: 14px;
    }

    @media (max-width: 900px) {
      .service-block, .service-block:nth-child(even) {
        flex-direction: column;
      }
      
      .service-block img, .service-text {
        width: 100%;
      }
      
      .hero h1 {
        font-size: 2.5rem;
      }
      
      .hero p {
        font-size: 1.2rem;
      }
      
      nav {
        flex-direction: column;
        gap: 15px;
        padding: 15px;
      }
    }

    @media (max-width: 600px) {
      .section {
        padding: 60px 15px;
      }
      
      .hero h1 {
        font-size: 2rem;
      }
      
      .hero p {
        font-size: 1rem;
      }
      
      .service-text {
        padding: 25px;
      }
      
      .chatbox {
        width: 90%;
        left: 5%;
        right: 5%;
      }
    }

    .file-upload-container {
      margin: 15px 0;
      text-align: left;
    }

    .file-label {
      display: block;
      margin-bottom: 5px;
      font-weight: bold;
      color: #555;
    }

    .file-input-wrapper {
      position: relative;
      overflow: hidden;
      display: inline-block;
      width: 100%;
    }

    .file-input-wrapper input[type="file"] {
      position: absolute;
      left: 0;
      top: 0;
      opacity: 0;
      width: 100%;
      height: 100%;
      cursor: pointer;
    }

    .file-custom {
      display: block;
      padding: 10px 15px;
      background: #f8f9fa;
      border: 1px solid #ddd;
      border-radius: 8px;
      color: #666;
      font-size: 14px;
    }

    .file-hint {
      font-size: 12px;
      color: #666;
      margin-top: 5px;
    }
  </style>
</head>
<body>

<nav>
  <div class="logo">
    <div class="logo-text">EBTGL Accounting Services</div>
  </div>
  <div class="nav-links">
    <a href="<?php echo isset($_SESSION['user_id']) ? 'index1.html' : 'index.html'; ?>">Home</a>
    <a href="servicesinfo.php">Services</a>
    <a href="about.html">About Us</a>
    <button onclick="openConsultPopup()">Log In</button>
  </div>
</nav>

<section id="home" class="hero">
  <div class="hero-content">
    <h1>Our Services</h1>
    <p>We offer essential financial services to help businesses thrive and grow.</p>
  </div>
</section>

<section class="section" id="services">
  <div class="section-title">
    <h2>Our Accounting Services</h2>
    <p>Comprehensive financial solutions for your business</p>
  </div>
  
  <div class="services-container">
    <div class="service-block">
      <img src="images/bookkeeping.jpg" alt="Bookkeeping">
      <div class="service-text">
        <h2>Bookkeeping</h2>
        <p>We provide accurate, timely, and organized bookkeeping solutions for your business, ensuring your financial records are always up to date and audit-ready. Our team meticulously tracks every transaction, categorizes expenses, and maintains your general ledger with precision.</p>
      </div>
    </div>

    <div class="service-block">
      <img src="images/reporting.jpg" alt="Financial Reporting">
      <div class="service-text">
        <h2>Financial Reporting</h2>
        <p>Our financial reports offer valuable insights into your business performance, enabling informed decisions and compliance with financial regulations. We prepare comprehensive income statements, balance sheets, and cash flow statements that give you a clear picture of your financial health.</p>
      </div>
    </div>
</section>

<div class="chat-toggle" onclick="toggleChat()">
  <i class="fas fa-comment-dots"></i>
</div>

<div class="chatbox" id="chatbox">
  <div class="chat-header" onclick="toggleChat()">
    <i class="fas fa-comments me-2"></i> Chat with Admin
  </div>
  <div class="chat-messages" id="chatMessages">
    <div class="message admin">
      Hello! Welcome to EBTGL Accounting Services. How can I help you today?
    </div>
  </div>
  <div class="chat-input">
    <input type="text" id="chatInput" placeholder="Type your message...">
    <button onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
  </div>
</div>

<footer>
  <div class="footer-content">
    <div class="footer-column">
      <h3>EBTGL Accounting Services</h3>
      <p>Providing professional accounting services with accuracy, integrity, and personalized attention to help your business thrive.</p>
      <div class="social-links">
        <a href="https://www.facebook.com/p/EB-Tarobal-Co-CPAs-100063656455449/"><i class="fab fa-facebook-f"></i></a>
      </div>
    </div>
    
    <div class="footer-column">
      <h3>Quick Links</h3>
      <a href="index.html">Home</a>
      <a href="servicesinfo.php">Services</a>
      <a href="about.html">About Us</a>
      <a href="#" onclick="openConsultPopup()">Client Login</a>
    </div>
    
    <div class="footer-column">
      <h3>Our Services</h3>
      <a href="servicesinfo.php">Bookkeeping</a>
      <a href="servicesinfo.php">Financial Reporting</a>
    </div>
    
    <div class="footer-column">
      <h3>Contact Us</h3>
      <p><i class="fas fa-map-marker-alt me-2"></i> Brgy Tibagan, San Juan City</p>
      <p><i class="fas fa-phone me-2"></i> (02) 8123-4567</p>
      <p><i class="fas fa-envelope me-2"></i> ebtgl5220712@gmail.com</p>
      <p><i class="fas fa-clock me-2"></i> Mon-Fri: 8AM - 5PM</p>
    </div>
  </div>
  
  <div class="copyright">
    &copy; 2025 EBTGL Accounting Services. All rights reserved.
  </div>
</footer>


<!-- LOGIN POPUP -->
<div id="popupOverlay" class="overlay hidden">
  <div class="popup">
    <span class="close" onclick="closeConsultPopup()">&times;</span>
    <div id="loginForm">
      <h2>Login</h2>
      <form id="loginFormElement">
        <input type="text" id="loginUsername" placeholder="Username" class="field" required>
        <input type="password" id="loginPassword" placeholder="Password" class="field" required>
        <button type="submit" class="btn">Log In</button>
      </form>
      <p class="toggle-text">Don't have an account yet? <span onclick="showSignup()">Sign up</span></p>
      <p class="toggle-text">
        <a onclick="showForgotPassword()" style="color: #007bff; text-decoration: underline;">Forgot Password?</a>
      </p>
    </div>
    
    <!-- SIGNUP FORM -->
    <div id="signupForm" class="hidden">
      <h2>Create Account & Request Consultation</h2>
      <form method="POST" action="signup.php" enctype="multipart/form-data" id="signupConsultForm">
        <input type="email" name="email" placeholder="Company Email Address" class="field" required>
        <input type="text" name="username" placeholder="Username" class="field" required>
        <input type="password" name="password" placeholder="Password" class="field" required>
        <input type="text" name="company_name" placeholder="Company Name" class="field" required>
        <input type="tel" name="phone" placeholder="Company Phone Number (optional)" class="field" pattern="^09\d{9}$" title="Must start with 09 and be exactly 11 digits if filled.">
        <input type="text" name="full_name" placeholder="Full Name" class="field" required>
        <input type="text" name="position" placeholder="Position in the Company" class="field" required>
        
        <div class="file-upload-container">
          <label for="permit" class="file-label">Business Permits and Licenses:</label>
          <div class="file-input-wrapper">
            <input type="file" name="permit" id="permit" required>
            <span class="file-custom">Choose file...</span>
          </div>
          <div class="file-hint">Please upload a clear copy of your business permits and licenses (PDF, JPG, PNG)</div>
        </div>
        
        <button type="submit" class="btn">Create Account & Submit Request</button>
      </form>
      <p class="toggle-text">Already have an account? <span onclick="showLogin()">Log in</span></p>
    </div>
  </div>
</div>

<!-- FORGOT PASSWORD POPUP -->
<div id="forgotPasswordPopup" class="overlay hidden">
  <div class="popup">
    <span class="close" onclick="closeForgotPasswordPopup()">&times;</span>
    <h2>Forgot Password</h2>
    <form id="forgotPasswordForm">
      <input type="email" id="resetEmail" placeholder="Enter your registered email" class="field" required>
      <button type="submit" class="btn">Send OTP</button>
    </form>
    <p class="status-message" id="resetStatus"></p>
  </div>
</div>

<!-- OTP VERIFICATION POPUP -->
<div id="otpPopupOverlay" class="overlay hidden">
  <div class="popup">
    <span class="close" onclick="closeOtpPopup()">&times;</span>
    <h3>Enter OTP & New Password</h3>
    <form id="resetForm">
      <input type="text" id="enteredOtp" placeholder="Enter OTP" class="field" required>
      <input type="password" id="newPassword" placeholder="New Password" class="field" required>
      <button type="submit" class="btn">Reset Password</button>
    </form>
    <p id="resetFeedback" class="status-message"></p>
  </div>
</div>

<script>
  // Chat functions
  function toggleChat() {
    const chatbox = document.getElementById('chatbox');
    chatbox.classList.toggle('active');
  }

  function sendMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    
    if (message) {
      const messagesDiv = document.getElementById('chatMessages');
      
      // Add user message
      const userMessage = document.createElement('div');
      userMessage.className = 'message user';
      userMessage.textContent = message;
      messagesDiv.appendChild(userMessage);
      
      // Clear input
      input.value = '';
      
      // Scroll to bottom
      messagesDiv.scrollTop = messagesDiv.scrollHeight;
      
      // Simulate admin reply after delay
      setTimeout(() => {
        const adminMessage = document.createElement('div');
        adminMessage.className = 'message admin';
        adminMessage.textContent = "Thanks for your message! Our team will get back to you shortly.";
        messagesDiv.appendChild(adminMessage);
        
        // Scroll to bottom again
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
      }, 1000);
    }
  }

  // Login popup functions
  function openConsultPopup() {
    document.getElementById("popupOverlay").classList.remove("hidden");
    showLogin();
  }
  
  function closeConsultPopup() {
    document.getElementById("popupOverlay").classList.add("hidden");
  }
  
  function showSignup() {
    document.getElementById("loginForm").classList.add("hidden");
    document.getElementById("signupForm").classList.remove("hidden");
  }
  
  function showLogin() {
    document.getElementById("signupForm").classList.add("hidden");
    document.getElementById("loginForm").classList.remove("hidden");
  }
  
  function showForgotPassword() {
    document.getElementById('popupOverlay').classList.add('hidden');
    document.getElementById('forgotPasswordPopup').classList.remove('hidden');
  }
  
  function closeForgotPasswordPopup() {
    document.getElementById('forgotPasswordPopup').classList.add('hidden');
    document.getElementById('popupOverlay').classList.remove('hidden');
  }
  
  function closeOtpPopup() {
    document.getElementById('otpPopupOverlay').classList.add('hidden');
  }

  // Form submission handlers
  document.getElementById("loginFormElement").addEventListener("submit", function(e) {
    e.preventDefault();

    const username = document.getElementById("loginUsername").value.trim();
    const password = document.getElementById("loginPassword").value.trim();

    const formData = new FormData();
    formData.append("username", username);
    formData.append("password", password);

    fetch("login.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "success") {
            // Set sessionStorage flags
            if (data.role === "admin") {
                sessionStorage.setItem("admin_logged_in", "true");
            } else if (data.role === "client") {
                sessionStorage.setItem("client_logged_in", "true");
            }

            alert(data.message);
            window.location.href = data.redirect;

        } else if (data.status === "pending") {
            alert(data.message);
        } else {
            alert(data.message); // for "error"
        }
    })
    .catch(error => {
        console.error("Error:", error);
        alert("Something went wrong. Please try again.");
    });
  });

  document.getElementById('forgotPasswordForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const email = document.getElementById('resetEmail').value;
    const status = document.getElementById('resetStatus');

    fetch('send_otp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    })
      .then(res => res.json())
      .then(data => {
        status.textContent = data.message;
        status.style.color = data.success ? 'green' : 'red';

        if (data.success) {
          // Close forgot popup and show OTP popup
          document.getElementById('forgotPasswordPopup').classList.add('hidden');
          document.getElementById('otpPopupOverlay').classList.remove('hidden');
        }
      });
  });

  document.getElementById('resetForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const otp = document.getElementById('enteredOtp').value;
    const newPassword = document.getElementById('newPassword').value;
    const feedback = document.getElementById('resetFeedback');

    fetch('verify_otp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ otp, password: newPassword })
    })
      .then(res => res.json())
      .then(data => {
        feedback.textContent = data.message;
        feedback.style.color = data.success ? 'green' : 'red';

        if (data.success) {
          setTimeout(() => {
            closeOtpPopup();
            alert("Password reset! Please log in again.");
          }, 1500);
        }
      });
  });

  // File input styling
  document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', function() {
      const fileName = this.files.length ? this.files[0].name : 'Choose file...';
      this.nextElementSibling.textContent = fileName;
    });
  });

  function logout() {
    window.location.href = 'index.html';
  }
</script>

</body>
</html>