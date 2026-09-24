<x-mail::message>
# Your material library is ready

You’re invited to **{{ $workspace }}** on OPAL as a **{{ $role }}**.

1. Sign in using the email address that received this invitation. Your Olsyn account also needs OPAL access.
2. Open **Connect** to download the Revit or Omniverse extension and follow its installation steps.
3. Choose **Connect account** in the extension. Approve the connection in your browser, then browse and apply published materials.

<x-mail::button :url="route('workspace.join')">Join your team</x-mail::button>

The invitation is valid for seven days. Already a member? The same link takes you to setup.

Materials keep the same identity across Revit and Omniverse. The extensions use your material drive when available, or fetch the selected textures securely over HTTPS.

If your company manages software installations, share the [IT setup notes]({{ route('connect.it') }}) with your IT team.

@if ($test)
This is a test of the OPAL onboarding email. It does not change your account or permissions.
@endif

The OPAL team
</x-mail::message>
