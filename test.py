import requests
import json

url = "https://piconnect.flattrade.in/PiConnectAPI/Limits"

# Try 4: text/plain
data2 = 'jData={"uid":"FT041391","actid":"FT041391"}&jKey=dummy'
r2 = requests.post(url, data=data2, headers={"Content-Type": "text/plain"})
print("\nExact string text/plain:")
print(r2.status_code, r2.text)
