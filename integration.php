<!DOCTYPE html>
<html>
<body>

<h1>SQL Acc SDK in PHP page</h1>

<link rel="stylesheet" href="Grid.css">

<?php

session_start();
// Read the contents of the JSON file
$jsonData = file_get_contents("php://input");
$sql = file_get_contents("config.json");
$logfile = "./error_log.text";
 ini_set("log_errors",1);
 ini_set("error_log",$logfile);

 error_reporting(E_ALL);

// Check if the JSON file was read successfully
if ($jsonData === false) {
    die('Error reading JSON file');
}

// Convert the JSON string into a PHP associative array
$data = json_decode($jsonData, true);
$sqldata = json_decode($sql, true);

if(!$data){
    parse_str($jsonData,$data);
}


// Check if the JSON decoding was successful
if ($data === null) {
    die('Error decoding JSON');
}

echo "Updated 01 May 2020<br>";


$ComServer = null;
function CheckLogin()
{
    global $ComServer;
    global $sqldata;
    // $com = new COM("WScript.Shell");
    // echo $com->Run("notepad.exe", 1, false);
    $ComServer = new COM("SQLAcc.BizApp") or die("Could not initialise SQLAcc.BizApp object.");

    $status = $ComServer->IsLogin();

    $username = $sqldata["username"];
    $userpassword= $sqldata["password"];
    $databasename= $sqldata["databaseName"]; 

    if ($status == true)
    {
        $ComServer->Logout();
    }
    $ComServer->Login($username, $userpassword,
                      "C:\\eStream\\SQLAccounting\\Share\\Default.DCF",  
                      $databasename); 
}

function formatDate($datastring)
{
    return date("Ymd",strtotime($datastring));
}

// function discountcode($sqldata, $data, $disc)
// {
//     foreach ($sqldata['discounts'] as $discount) {
//         if (strtolower($discount['name']) == strtolower($disc['name'])) {
//             return $discount['code'];
//             break;
//         } else if (isVoucher($discount['name'])) {
//             return $discount['code'];
//             break;
//         }
//     }

//     return ""; // Return an empty string if no match is found
// }

function discountcode2($sqldata, $data, $sales, $outletid)
{
    foreach ($sqldata['discounts'] as $discount) {
        if (strtolower($discount['name']) == strtolower($sales['name'])) {
            return $discount['code'];
        }
    }

    // Check if the discount name contains the word "Voucher"
    foreach ($sqldata['discounts'] as $discount) {
        if (stripos($sales['name'], 'voucher') !== false) {
            return $discount['code'];
        }
    }

    return $sqldata['outlets'][$outletid]['salesId']; // Return salesId if no match is found
}



function PostDataIV($data, $sqldata)
{
    global $ComServer;
    $outletid = $data["outlet_id"] - 1;

    $BizObject = $ComServer->BizObjects->Find("SL_IV");
    $lMain = $BizObject->DataSets->Find("MainDataSet"); // lMain contains master data
    $lDetail = $BizObject->DataSets->Find("cdsDocDetail"); // lDetail contains detail data

    $totalAmount = $data['summary']['nett'];

    // Open New Cash Sale
    $BizObject->New();
    $lMain->FindField("DocKey")->value = -1;

    if (isset($sqldata['outlets'][$outletid]['nickname'])) {
        $lMain->FindField("DocNo")->AsString = $sqldata['outlets'][$outletid]['nickname'] . "-" . date("Y-m-d", strtotime($data['openAt']));
    } else {
        $lMain->FindField("DocNo")->AsString = formatDate($data['openAt']) . "_outlet " . $data['outlet_id'];
    }

    $lMain->FindField("DocDate")->value = date("Y-m-d", strtotime($data['openAt'])); // Table Create Time
    $lMain->FindField("PostDate")->value = date("Y-m-d", strtotime($data['openAt'])); // Table Modified Time

    if (isset($sqldata['outlets'][$outletid]['customercode'])) {
        $lMain->FindField("Code")->AsString = $sqldata['outlets'][$outletid]['customercode'];
    } else {
        $lMain->FindField("Code")->AsString = "300-C0001";
    }

    $lMain->FindField("CompanyName")->AsString = $sqldata['outlets'][$outletid]['name'];

    $lMain->FindField("Address1")->AsString = "Outlet ID: " . $data['outlet_id'];
    $lMain->FindField("Address2")->AsString = "Device ID: " . $data['device_id']; // Optional
    $lMain->FindField("Address3")->AsString = "Open At: " . $data['openAt']; // Optional
    $lMain->FindField("Address4")->AsString = "Closed At: " . $data['closedAt'];

    if (isset($sqldata['outlets'][$outletid]['nickname'])) {
        $lMain->FindField("Description")->AsString = $sqldata['outlets'][$outletid]['nickname'] . "-" . date("Y-m-d", strtotime($data['openAt']));
    } else {
        $lMain->FindField("Description")->AsString = formatDate($data['openAt']) . "_outlet " . $data['outlet_id'];
    }

    if (isset($data['reports']['disc_sales']) && is_array($data['reports']['disc_sales'])) {
        foreach ($data['reports']['disc_sales'] as $SalesType => $SalesData) {
            try {
                $lDetail->Append();
                $lDetail->FindField("DtlKey")->value = -1;
                $lDetail->FindField("DocKey")->value = -1;
                $lDetail->FindField("Seq")->value = -1;
                $lDetail->FindField("Account")->AsString = discountcode2($sqldata, $data, $SalesData, $outletid);
                $lDetail->FindField("ItemCode")->AsString = discountcode2($sqldata, $data, $SalesData, $outletid);
                $lDetail->FindField("Description")->AsString = $SalesData['name'];
                $lDetail->FindField("Qty")->AsFloat = 1;
                $lDetail->FindField("Tax")->AsString = "";
                $lDetail->FindField("TaxRate")->AsString = "";
                $lDetail->FindField("TaxInclusive")->value = false;
                $lDetail->FindField("Amount")->AsFloat = $SalesData['total'];
                $lDetail->FindField("UnitPrice")->AsFloat = $SalesData['total'];

                $totalAmount -= $SalesData['total'];

                $lDetail->Post();
            } catch (Exception $e) {
                echo "Error processing sales data: " . $e->getMessage();
            }
        }
    } else {
        echo "Error: 'disc_sales' is not set or not an array.";
    }

    // Subtotal & Invoice ID
    // Insert Data - Detail
    // For Tax Inclusive = False with override Tax Amount
    $lDetail->Append();
    $lDetail->FindField("DtlKey")->value = -1;
    $lDetail->FindField("DocKey")->value = -1;
    $lDetail->FindField("Seq")->value = -1;
    $lDetail->FindField("Account")->AsString = $sqldata['outlets'][$outletid]['salesId'];
    $lDetail->FindField("ItemCode")->AsString = "";
    $lDetail->FindField("Description")->AsString = $sqldata['outlets'][$outletid]['name'] . "_" . formatDate($data['openAt']);
    $lDetail->FindField("Qty")->AsFloat = 1;
    $lDetail->FindField("Tax")->AsString = "";
    $lDetail->FindField("TaxRate")->AsString = "";
    $lDetail->FindField("TaxInclusive")->value = false;
    $lDetail->FindField("UnitPrice")->AsFloat = $totalAmount;
    $lDetail->FindField("Amount")->AsFloat = $totalAmount;

    $lDetail->Post();

    // Insert Data - Detail
    // For Tax Inclusive = False with override Tax Amount
    foreach ($data['summary']['taxes'] as $taxType => $taxData) {
        $lDetail->Append();
        $lDetail->FindField("DtlKey")->value = -1;
        $lDetail->FindField("DocKey")->value = -1;
        $lDetail->FindField("Seq")->value = -1;
        $lDetail->FindField("ItemCode")->AsString = "";
        $lDetail->FindField("Description")->AsString = $taxData['name'];
        $lDetail->FindField("Qty")->AsFloat = 1;

        if (stripos($taxType, "sst") !== false) {

           // $lDetail->FindField("Tax")->AsString = $sqldata['outlets'][$outletid]['sstCode'];
            $lDetail->FindField("Account")->AsString = $sqldata['outlets'][$outletid]['sstCode'];
            $lDetail->FindField("TaxRate")->AsString = "";
            $lDetail->FindField("TaxInclusive")->value = false;
            $lDetail->FindField("Amount")->AsFloat = $taxData['total'];
            $lDetail->FindField("UnitPrice")->AsFloat = $taxData['total'];
            $lDetail->FindField("TaxAmt")->AsFloat == $taxData['total'];
        } else {
            $lDetail->FindField("Tax")->AsString =  "";
            $lDetail->FindField("TaxRate")->AsString = "";
            $lDetail->FindField("TaxInclusive")->value = false;
            $lDetail->FindField("Account")->AsString = $sqldata['outlets'][$outletid]['salesId'];
            $lDetail->FindField("Amount")->AsFloat = $taxData['total'];
            $lDetail->FindField("UnitPrice")->AsFloat = $taxData['total'];
            $lDetail->FindField("TaxAmt")->AsFloat == $taxData['total'];
        }

        $lDetail->Post();
    }

    if ($data['summary']['rounding'] != 0) {
        $lDetail->Append();
        $lDetail->FindField("DtlKey")->value = -1;
        $lDetail->FindField("DocKey")->value = -1;
        $lDetail->FindField("Seq")->value = -1;
        $lDetail->FindField("Account")->AsString = $sqldata['outlets'][$outletid]['salesId'];
        $lDetail->FindField("ItemCode")->AsString = "";
        $lDetail->FindField("Description")->AsString = "Rounding";
        $lDetail->FindField("Qty")->AsFloat = 1;
        $lDetail->FindField("Tax")->AsString = "";
        $lDetail->FindField("TaxRate")->AsString = "";
        $lDetail->FindField("TaxInclusive")->value = false;
        $lDetail->FindField("UnitPrice")->AsFloat = $data['summary']['rounding'];
        $lDetail->FindField("Amount")->AsFloat = $data['summary']['rounding'];
        $lDetail->Post();
    }

    $BizObject->Save();
    $BizObject->Close();
}


function GetPaymentMethodForType($data, $paymentTypeString, $sqldata, $mode)
{
    $outletcode = $data["outlet_id"];
    $ocode = $outletcode - 1;
    $pcode;

    foreach ($sqldata['outlets'][$ocode]['paymentMethods'] as $paymentMethod) {
        if ($paymentMethod['mode'] == "5") {
            if (strtolower($paymentMethod['name']) === strtolower($paymentTypeString)) {
                $pcode = $paymentMethod['code'];
            }
        } else if ($paymentMethod['mode'] === $data['summary']['payments'][$paymentTypeString]['mode'] && $paymentMethod['mode'] != "5") {
            $pcode = $paymentMethod['code'];
        }
    }

    return $pcode;
}



function PostDataPM($data,$paymentTypeString,$sqldata,$mode)
{
    global $ComServer;
    $outletcode = $data["outlet_id"];
    $ocode = $outletcode - 1;
    
    $BizObject = $ComServer->BizObjects->Find("AR_PM");
    $lMain = $BizObject->DataSets->Find("MainDataSet"); #lMain contains master data
    $lDetail = $BizObject->DataSets->Find("cdsKnockOff"); #lDetail contains detail data

    $BizObject->New();
    $lMain->FindField("DOCKEY")->Value = -1;

   if (isset($sqldata['outlets'][$outletid]['nickname'])) {
    $uniqueIdentifier = uniqid(); // Generate a unique identifier 
    $lMain->FindField("DocNo")->AsString = $sqldata['outlets'][$outletid]['nickname'] . "-" . date("Y-m-d", strtotime($data['openAt'])) . "-" . $uniqueIdentifier;
} else {
    $lMain->FindField("DocNo")->AsString = formatDate($data['openAt']) . "_" . $sqldata['outlets'][$ocode]['name'] . "_" . $paymentTypeString;
}
    
    if(isset($sqldata['outlets'][$ocode]['customercode'])) {
        $lMain->FindField("Code")->AsString = $sqldata['outlets'][$ocode]['customercode']; 
    }else{
        $lMain->FindField("Code")->AsString = "300-C0001"; 
    }

    $lMain->FindField("DocDate")->value = date("Y-m-d", strtotime($data['openAt'])); #Table Create Time
    $lMain->FindField("PostDate")->value = date("Y-m-d", strtotime($data['openAt']));

    if(isset($sqldata['outlets'][$ocode]['nickname'])) {
        $lMain->FindField("Description")->AsString  = $sqldata['outlets'][$ocode]['nickname'] . "-" .$paymentTypeString. "-" .date("Y-m-d", strtotime($data['openAt']));
    }else{
        $lMain->FindField("Description")->AsString  = formatDate($data['openAt']) ."_". $sqldata['outlets'][$ocode]['name']. "_" . $paymentTypeString;
    }
    $lMain->FindField("DocAmt")->AsFloat = $data['summary']['payments'][$paymentTypeString]['total'];
    $lMain->FindField("PaymentMethod")->AsString = GetPaymentMethodForType($data,$paymentTypeString,$sqldata,$mode);# Bank or Cash Account for Cash payments
    $lMain->FindField("ChequeNumber")->AsString = "";
    $lMain->FindField("BankCharge")->AsFloat = 0;
    $lMain->FindField("Cancelled")->AsString = "F";

    #Knock Off IV  
    if(isset($sqldata['outlets'][$ocode]['nickname'])) {
        $V = array("IV", $sqldata['outlets'][$ocode]['nickname'] . "-" . date("Y-m-d", strtotime($data['openAt'])));
    }
    else{
        $V = array("IV", formatDate($data['openAt']) ."_outlet ".$data['outlet_id']);  #DocType, DocNo
    }

    if ($lDetail->Locate("DocType;DocNo", $V, False, False)) {
        $lDetail->Edit();
        $lDetail->FindField("KOAmt")->AsFloat = $data['summary']['payments'][$paymentTypeString]['total']; #Partial Knock off
        $lDetail->FindField("KnockOff")->AsString = "T";
        $outstandingAmount = $data['summary']['totalSales']['total'] - $data['summary']['payments'][$paymentTypeString]['total'];
        $lDetail->FindField("Outstanding")->value = $outstandingAmount;

        $lDetail->Post();
    }

    $BizObject->Save();
    $BizObject->Close();
}

    CheckLogin();

    PostDataIV($data,$sqldata);
    
    foreach ($data['summary']['payments'] as $paymentType =>$paymentModes) {
        $paymentTypeString = (string) $paymentType;
        $mode = $paymentModes['mode'];

        if($data['summary']['payments'][$paymentTypeString]['total'] > 0){
            PostDataPM($data,$paymentTypeString,$sqldata,$mode);
        }
    }

header('Content-Type:application/json');
?> 


</body>
</html>