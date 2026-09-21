package com.propertypoint.npocrmbridge;

import android.Manifest;
import android.app.Activity;
import android.content.*;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Bundle;
import android.provider.CallLog;
import android.provider.ContactsContract;
import android.view.*;
import android.widget.*;
import java.io.*;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.*;
import java.util.regex.*;

public class MainActivity extends Activity {
    static final int REQ_CALLS = 9001;
    static final int REQ_CONTACT = 9002;
    final String CRM = "https://npocrm.propertypoint.online/device-share/native";
    LinearLayout root;
    @Override public void onCreate(Bundle b) { super.onCreate(b); handleIntent(getIntent()); }
    @Override public void onNewIntent(Intent i) { super.onNewIntent(i); setIntent(i); handleIntent(i); }

    void handleIntent(Intent i) {
        if (i == null) { showHome(); return; }
        if ("npocrm".equals(i.getScheme()) && "recent-calls".equals(i.getHost())) { openRecentCalls(); return; }
        if ("npocrm".equals(i.getScheme()) && "phone-contacts".equals(i.getHost())) { openPhoneContacts(); return; }
        if ("npocrm".equals(i.getScheme()) && "whatsapp-contact".equals(i.getHost())) { openWhatsAppForContactShare(); return; }
        if (Intent.ACTION_SEND.equals(i.getAction())) { handleShare(i); return; }
        showHome();
    }

    void showHome() {
        root = new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setPadding(40,60,40,40);
        TextView t = new TextView(this); t.setText("NPO CRM Device Bridge\n\nUse Recent Calls from the CRM Add Lead screen, or share a WhatsApp contact to NPO CRM from Android."); t.setTextSize(18); root.addView(t);
        Button b = new Button(this); b.setText("Open Recent Calls"); b.setOnClickListener(v -> openRecentCalls()); root.addView(b);
        setContentView(root);
    }

    void openRecentCalls() {
        if (checkSelfPermission(Manifest.permission.READ_CALL_LOG) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.READ_CALL_LOG}, REQ_CALLS); return;
        }
        showCalls();
    }
    void openPhoneContacts() {
        try {
            Intent pick = new Intent(Intent.ACTION_PICK, ContactsContract.CommonDataKinds.Phone.CONTENT_URI);
            startActivityForResult(pick, REQ_CONTACT);
        } catch (Exception e) {
            Toast.makeText(this, "Phone Contacts picker is not available on this device.", Toast.LENGTH_LONG).show();
            finish();
        }
    }

    void openWhatsAppForContactShare() {
        try {
            Intent launch = getPackageManager().getLaunchIntentForPackage("com.whatsapp");
            if (launch == null) launch = getPackageManager().getLaunchIntentForPackage("com.whatsapp.w4b");
            if (launch == null) {
                Toast.makeText(this, "WhatsApp is not installed. You can share a contact from Android Contacts instead.", Toast.LENGTH_LONG).show();
                finish();
                return;
            }
            Toast.makeText(this, "In WhatsApp: open the contact → Share contact → choose NPO CRM.", Toast.LENGTH_LONG).show();
            startActivity(launch);
            finish();
        } catch (Exception e) {
            Toast.makeText(this, "Could not open WhatsApp.", Toast.LENGTH_LONG).show();
            finish();
        }
    }

    @Override protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQ_CONTACT) return;
        if (resultCode != RESULT_OK || data == null || data.getData() == null) { finish(); return; }
        Cursor c = null;
        try {
            c = getContentResolver().query(data.getData(),
                new String[]{ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME, ContactsContract.CommonDataKinds.Phone.NUMBER},
                null, null, null);
            if (c != null && c.moveToFirst()) {
                String name = c.getString(0);
                String phone = c.getString(1);
                openLead(name == null ? "" : name, phone == null ? "" : phone, "");
                return;
            }
            Toast.makeText(this, "No phone number found for that contact.", Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            Toast.makeText(this, "Could not read that contact.", Toast.LENGTH_LONG).show();
        } finally { if (c != null) c.close(); }
        finish();
    }

    @Override public void onRequestPermissionsResult(int r,String[] p,int[] g){ super.onRequestPermissionsResult(r,p,g); if(r==REQ_CALLS && g.length>0 && g[0]==PackageManager.PERMISSION_GRANTED) showCalls(); else showHome(); }

    void showCalls() {
        root = new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setPadding(24,30,24,20);
        TextView h=new TextView(this); h.setText("Select a recent call"); h.setTextSize(22); root.addView(h);
        Cursor c=null;
        try {
            c=getContentResolver().query(CallLog.Calls.CONTENT_URI,
                new String[]{CallLog.Calls.CACHED_NAME,CallLog.Calls.NUMBER,CallLog.Calls.DATE,CallLog.Calls.TYPE},
                null,null,CallLog.Calls.DATE+" DESC LIMIT 50");
            if(c!=null) while(c.moveToNext()) {
                String name=c.getString(0); String phone=c.getString(1); if(phone==null) phone=""; if(name==null || name.trim().isEmpty()) name=phone;
                final String fn=name, fp=phone; String label=fn+"\n"+fp;
                Button row=new Button(this); row.setText(label); row.setGravity(Gravity.START|Gravity.CENTER_VERTICAL); row.setOnClickListener(v->openLead(fn,fp,"")); root.addView(row);
            }
        } catch(Exception e) { Toast.makeText(this,"Could not read recent calls: "+e.getMessage(),Toast.LENGTH_LONG).show(); }
        finally { if(c!=null)c.close(); }
        Button cancel=new Button(this); cancel.setText("Cancel"); cancel.setOnClickListener(v->finish()); root.addView(cancel); setContentView(root);
    }

    void handleShare(Intent i) {
        String mime=i.getType()==null?"":i.getType(); String text=i.getStringExtra(Intent.EXTRA_TEXT); String title=i.getStringExtra(Intent.EXTRA_TITLE); String name=title==null?"":title; String phone=""; String email="";
        if(text!=null){ phone=findPhone(text); if(name.isEmpty()) name=findName(text); }
        Uri u=i.getParcelableExtra(Intent.EXTRA_STREAM); if(u!=null && mime.toLowerCase().contains("vcard")) { try { String v=readUri(u); name=findV(v,"FN"); phone=findV(v,"TEL"); email=findV(v,"EMAIL"); }catch(Exception ignored){} }
        if(phone.isEmpty() && text!=null) phone=findPhone(text);
        if(name.isEmpty() && phone.isEmpty()){ Toast.makeText(this,"No contact name/number found. In WhatsApp use Share contact and select NPO CRM.",Toast.LENGTH_LONG).show(); return; }
        openLead(name,phone,email);
    }

    void openLead(String name,String phone,String email){ try{
        String q="?name="+enc(name)+"&phone="+enc(phone)+"&email="+enc(email); startActivity(new Intent(Intent.ACTION_VIEW,Uri.parse(CRM+q))); finish();
    }catch(Exception e){Toast.makeText(this,"Could not open NPO CRM",Toast.LENGTH_LONG).show();} }
    String enc(String s){return URLEncoder.encode(s==null?"":s,StandardCharsets.UTF_8);}
    String readUri(Uri u)throws Exception{InputStream in=getContentResolver().openInputStream(u); ByteArrayOutputStream o=new ByteArrayOutputStream(); byte[] b=new byte[4096]; int n; while((n=in.read(b))>0)o.write(b,0,n); in.close(); return o.toString(StandardCharsets.UTF_8);}
    String findV(String v,String key){Matcher m=Pattern.compile("(?:^|\\R)"+key+"(?:;[^:]*)?:([^\\r\\n]+)",Pattern.CASE_INSENSITIVE).matcher(v);return m.find()?m.group(1).trim():"";}
    String findPhone(String s){Matcher m=Pattern.compile("(?:\\+?\\d[\\d\\s().-]{7,}\\d)").matcher(s);return m.find()?m.group().trim():"";}
    String findName(String s){String[] lines=s.split("\\R");return lines.length>0?lines[0].trim():"";}
}
