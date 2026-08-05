#include <CoreFoundation/CoreFoundation.h>
#include <Security/Security.h>
#include <stdio.h>
#include <string.h>

static void clear_secret(void *data, size_t length) {
	volatile unsigned char *cursor = (volatile unsigned char *) data;

	while (length > 0) {
		*cursor = 0;
		cursor++;
		length--;
	}
}

static int print_error(const char *operation, OSStatus status) {
	CFStringRef message = SecCopyErrorMessageString(status, NULL);

	fprintf(stderr, "%s failed (%d)", operation, (int) status);
	if (message != NULL) {
		char buffer[512];
		if (CFStringGetCString(message, buffer, sizeof(buffer), kCFStringEncodingUTF8)) {
			fprintf(stderr, ": %s", buffer);
		}
		CFRelease(message);
	}
	fputc('\n', stderr);
	return 1;
}

int main(int argc, char **argv) {
	const char *service;
	const char *account;
	const char *application_path;
	SecKeychainItemRef item = NULL;
	SecTrustedApplicationRef trusted_application = NULL;
	SecAccessRef access = NULL;
	CFMutableArrayRef trusted_applications = NULL;
	CFStringRef descriptor = NULL;
	UInt32 password_length = 0;
	void *password_data = NULL;
	OSStatus status;

	if (argc != 4) {
		fprintf(stderr, "Usage: %s SERVICE ACCOUNT APPLICATION_PATH\n", argv[0]);
		return 64;
	}

	service = argv[1];
	account = argv[2];
	application_path = argv[3];

	status = SecKeychainSetUserInteractionAllowed(true);
	if (status != errSecSuccess) {
		return print_error("Enabling Keychain user approval", status);
	}

	status = SecKeychainFindGenericPassword(
		NULL,
		(UInt32) strlen(service),
		service,
		(UInt32) strlen(account),
		account,
		&password_length,
		&password_data,
		&item
	);
	if (status != errSecSuccess) {
		return print_error("Opening saved WordPress.org credential", status);
	}

	if (password_data != NULL) {
		clear_secret(password_data, password_length);
		SecKeychainItemFreeContent(NULL, password_data);
	}

	status = SecTrustedApplicationCreateFromPath(application_path, &trusted_application);
	if (status != errSecSuccess) {
		CFRelease(item);
		return print_error("Creating SVN trust rule", status);
	}

	trusted_applications = CFArrayCreateMutable(NULL, 1, &kCFTypeArrayCallBacks);
	CFArrayAppendValue(trusted_applications, trusted_application);
	descriptor = CFStringCreateWithCString(NULL, service, kCFStringEncodingUTF8);
	status = SecAccessCreate(descriptor, trusted_applications, &access);
	if (status != errSecSuccess) {
		CFRelease(descriptor);
		CFRelease(trusted_applications);
		CFRelease(trusted_application);
		CFRelease(item);
		return print_error("Creating Keychain access rules", status);
	}

	status = SecKeychainItemSetAccess(item, access);
	if (status != errSecSuccess) {
		CFRelease(access);
		CFRelease(descriptor);
		CFRelease(trusted_applications);
		CFRelease(trusted_application);
		CFRelease(item);
		return print_error("Saving Keychain access rules", status);
	}

	printf("Trusted current SVN binary: %s\n", application_path);

	CFRelease(access);
	CFRelease(descriptor);
	CFRelease(trusted_applications);
	CFRelease(trusted_application);
	CFRelease(item);
	return 0;
}
