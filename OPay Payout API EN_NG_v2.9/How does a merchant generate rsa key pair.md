How does a merchant generate his own public and private key pair ? https://en.wikipedia.org/wiki/RSA_(cryptosystem) 

# RSA key pair 

An RSA key pair contains the private key and the public key. The private key is required for generating the signature, while the public key is used for verifying the signature. Many tools can be used to generate the RSA key pair. The following steps assume that you use OpenSSL to generate the RSA key pair. 

# # 1. Generating the private key 

openssl genrsa -out client_private_key_php_dotnet.pem 2048 

# 2. If you are a Java developer, convert the private key to PKCS8 format openssl pkcs8 -topk8 -inform PEM -in client_private_key_php_dotnet.pem -outform PEM -nocrypt -out client_private_key_pkcs8.pem 

# # 3. Generate the public key 

openssl rsa -in client_private_key_php_dotnet.pem -pubout -out client_public_key_php_dotnet.pem 

# # 4. Generate the private key that can be used in Java 

cat client_private_key_pkcs8.pem | grep -v "^\-" | tr -d "\n" | sed 's/%$//' > client_private_key_java.pem 

# 5. Generate the public key that can be used in Java 

cat client_public_key_php_dotnet.pem | grep -v "^\-" | tr -d "\n" | sed 's/%$//' > client_public_key_java.pem 

