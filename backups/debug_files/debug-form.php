<?php
session_start();

echo "<h1>Form Debug Info</h1>";

// Show POST data
echo "<h2>POST Data:</h2>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

// Show expected values
echo "<h2>Expected Values:</h2>";
echo "<p>payment_method should be one of: 'cash', 'stripe', or 'woo_funds'</p>";

// Show the form HTML
echo "<h2>Debug Form:</h2>";
?>

<form method="post" action="">
  <h3>Payment Method Test</h3>
  
  <label>
    <input type="radio" name="payment_method" value="cash"> Cash
  </label><br>
  
  <label>
    <input type="radio" name="payment_method" value="stripe"> Stripe
  </label><br>
  
  <label>
    <input type="radio" name="payment_method" value="woo_funds"> Account Credit
  </label><br>
  
  <button type="submit" name="submit_payment" value="1">Test Submit</button>
</form>